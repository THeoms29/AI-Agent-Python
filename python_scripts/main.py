# main.py (replace whole file with the following)
import os
import sys
import json
import traceback
import asyncio
from dotenv import load_dotenv

# CRITICAL FIX untuk Windows asyncio error (only if available)
if sys.platform == 'win32':
    try:
        asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())
    except Exception:
        # some Windows Python builds may not expose this; ignore if missing
        pass

# load env
load_dotenv()

ELEVENLABS_API_KEY = os.getenv("ELEVENLABS_API_KEY")
ELEVENLABS_AGENT_ID = os.getenv("ELEVENLABS_AGENT_ID")

# output dir
AUDIO_OUTPUT_DIR = "temp_audio"
os.makedirs(AUDIO_OUTPUT_DIR, exist_ok=True)

# try to import elevenlabs, but be defensive
try:
    from elevenlabs.client import ElevenLabs
    from elevenlabs.core.api_error import ApiError
except Exception as e:
    ElevenLabs = None
    ApiError = Exception
    # do not crash on import; we'll report later

def safe_print_json(obj):
    print(json.dumps(obj, ensure_ascii=False))

def get_agent_response(user_input):
    try:
        if not ELEVENLABS_API_KEY:
            return {"success": False, "error": "ELEVENLABS_API_KEY tidak ditemukan di .env"}

        if ElevenLabs is None:
            return {"success": False, "error": "elevenlabs SDK tidak dapat diimport. Pastikan venv terpasang dan paket 'elevenlabs' ada."}

        client = ElevenLabs(api_key=ELEVENLABS_API_KEY)

        # Try conversational ai only if agent id set
        if ELEVENLABS_AGENT_ID:
            try:
                res = use_conversational_ai(client, user_input)
                # jika conversational sukses, return
                if res.get("success"):
                    return res
                # jika gagal tapi bukan fatal, fallback ke TTS
            except Exception as e:
                # tangkap semua error conversation, log, dan fallback
                print("Conversational AI failed: " + str(e), file=sys.stderr)
                print(traceback.format_exc(), file=sys.stderr)
                print("Falling back to Text-to-Speech...", file=sys.stderr)

        # fallback ke TTS (elevenlabs TTS, jika gagal -> fallback local)
        tts_res = use_text_to_speech(client, user_input)
        if tts_res.get("success"):
            return tts_res

        # jika TTS remote gagal, coba fallback lokal
        print("Remote TTS failed, attempting local TTS fallback...", file=sys.stderr)
        fallback_ok, fallback_err = fallback_local_tts(f"Anda berkata: {user_input}.", out_path=os.path.join(AUDIO_OUTPUT_DIR, "fallback.wav"))
        if fallback_ok:
            return {
                "success": True,
                "response_text": f"FALLBACK: Anda berkata: {user_input}",
                "audio_path": os.path.join(AUDIO_OUTPUT_DIR, "fallback.wav"),
                "method": "local_fallback"
            }
        else:
            return {
                "success": False,
                "error": "TTS remote gagal dan fallback lokal juga gagal.",
                "remote": tts_res,
                "fallback_error": fallback_err
            }

    except Exception as e:
        tb = traceback.format_exc()
        print("ERROR in get_agent_response: " + str(e), file=sys.stderr)
        print(tb, file=sys.stderr)
        return {"success": False, "error": str(e), "traceback": tb}

def use_conversational_ai(client, user_input):
    """
    Robust wrapper for conversational AI.
    Tries a few method names to handle SDK differences.
    """
    try:
        response_text = ""
        audio_data = b""
        conversation_id = None

        conv_client = getattr(client, "conversational_ai", None) or getattr(client, "conversations", None)

        if not conv_client:
            return {"success": False, "error": "Conversational client not available in SDK."}

        # Try several possible ways to create/get conversation (safe)
        conversation = None
        # try get_or_create_conversation
        if hasattr(conv_client, "get_or_create_conversation"):
            conversation = conv_client.get_or_create_conversation(agent_id=ELEVENLABS_AGENT_ID)
        # try create_conversation
        elif hasattr(conv_client, "create_conversation"):
            conversation = conv_client.create_conversation(agent_id=ELEVENLABS_AGENT_ID)
        # try conversations.create on top-level
        elif hasattr(client, "conversations") and hasattr(client.conversations, "create"):
            conversation = client.conversations.create(agent_id=ELEVENLABS_AGENT_ID)
        else:
            # cannot create conversation with installed SDK
            return {"success": False, "error": "No compatible conversation creation method found in SDK."}

        # extract id if available
        conversation_id = getattr(conversation, "conversation_id", None) or getattr(conversation, "id", None)

        # send message
        if hasattr(conv_client, "send_message"):
            response_stream = conv_client.send_message(conversation_id=conversation_id, text=user_input)
        elif hasattr(client, "conversations") and hasattr(client.conversations, "send_message"):
            response_stream = client.conversations.send_message(conversation_id=conversation_id, text=user_input)
        else:
            return {"success": False, "error": "No compatible send_message method in SDK."}

        # iterate stream
        for event in response_stream:
            if hasattr(event, 'message') and getattr(event, "message") and hasattr(event.message, "text"):
                response_text += event.message.text
            elif hasattr(event, 'text'):
                response_text += event.text
            if hasattr(event, 'audio') and event.audio:
                audio_data += event.audio

        audio_path = None
        if audio_data:
            audio_filename = f"response_{os.urandom(8).hex()}.mp3"
            audio_path = os.path.join(AUDIO_OUTPUT_DIR, audio_filename)
            with open(audio_path, "wb") as f:
                f.write(audio_data)

        return {"success": True, "response_text": response_text or "Response received", "audio_path": audio_path, "method": "conversational_ai", "conversation_id": conversation_id}

    except Exception as e:
        # bubble up the exception for get_agent_response to handle (and log)
        raise

def use_text_to_speech(client, user_input):
    """
    Use ElevenLabs TTS. Returns dict with success True/False plus details.
    """
    try:
        response_text = f"Anda berkata: {user_input}. Bagaimana saya bisa membantu Anda hari ini?"

        # NOTE: ensure voice_id is a real voice id present in your account
        voice_id = os.getenv("ELEVENLABS_VOICE_ID") or "xChNffR8mWkGIrdSUYsg"

        # call convert; be defensive about ApiError
        audio_generator = None
        try:
            audio_generator = client.text_to_speech.convert(
                voice_id=voice_id,
                text=response_text,
                model_id="eleven_multilingual_v2",
                output_format="mp3_44100_128"
            )
        except ApiError as e:
            # return the ApiError details to caller
            return {"success": False, "error": "ApiError", "details": str(e)}

        audio_filename = f"response_{os.urandom(8).hex()}.mp3"
        audio_path = os.path.join(AUDIO_OUTPUT_DIR, audio_filename)

        with open(audio_path, "wb") as f:
            for chunk in audio_generator:
                if chunk:
                    f.write(chunk)

        return {"success": True, "response_text": response_text, "audio_path": audio_path, "method": "text_to_speech"}

    except Exception as e:
        tb = traceback.format_exc()
        return {"success": False, "error": str(e), "traceback": tb}

def fallback_local_tts(text, out_path="fallback.wav"):
    """
    Offline fallback using pyttsx3 (Windows-friendly)
    """
    try:
        import pyttsx3
    except Exception as e:
        return False, f"pyttsx3 import failed: {e}"

    try:
        engine = pyttsx3.init()
        engine.save_to_file(text, out_path)
        engine.runAndWait()
        return True, None
    except Exception as e:
        return False, str(e)

def test_connection():
    try:
        if not ELEVENLABS_API_KEY:
            return {"success": False, "error": "ELEVENLABS_API_KEY not found in .env"}

        if ElevenLabs is None:
            return {"success": False, "error": "elevenlabs SDK not importable"}

        client = ElevenLabs(api_key=ELEVENLABS_API_KEY)

        # try list voices in a defensive way
        try:
            voices_response = client.voices.get_all()
            count = len(getattr(voices_response, "voices", []))
        except Exception as e:
            return {"success": False, "error": "voices list failed", "details": str(e)}

        return {"success": True, "message": "Connection successful", "voices_count": count}

    except Exception as e:
        return {"success": False, "error": str(e), "traceback": traceback.format_exc()}

if __name__ == "__main__":
    try:
        if len(sys.argv) > 1 and sys.argv[1] == "test":
            safe_print_json(test_connection())
            sys.exit(0)

        elif len(sys.argv) > 1:
            user_input_from_laravel = sys.argv[1]
            res = get_agent_response(user_input_from_laravel)
            safe_print_json(res)
            # exit code 0 even on false-positive to let Laravel parse JSON, but include success flag
            sys.exit(0)
        else:
            safe_print_json({"success": False, "error": "No input provided. Usage: python main.py 'your message' OR python main.py test"})
            sys.exit(0)
    except Exception as e:
        tb = traceback.format_exc()
        safe_print_json({"success": False, "error": str(e), "traceback": tb})
        sys.exit(1)
