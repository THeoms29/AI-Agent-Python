# Awal file
import os
import sys
import json
import traceback

# Untuk Windows event loop
import platform
if platform.system() == "Windows":
    try:
        import asyncio
        asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())
    except Exception:
        pass

from dotenv import load_dotenv
load_dotenv()

API_KEY = os.getenv("ELEVENLABS_API_KEY")
VOICE_ID = os.getenv("ELEVENLABS_VOICE_ID")
AGENT_ID = os.getenv("ELEVENLABS_AGENT_ID")

# Import SDK
try:
    from elevenlabs.client import ElevenLabs
    from elevenlabs.core.api_error import ApiError
except ImportError:
    ElevenLabs = None
    ApiError = Exception

def get_agent_response(text):
    if not API_KEY:
        return {"success": False, "error": "Missing ELEVENLABS_API_KEY"}
    client = ElevenLabs(api_key=API_KEY)

    # Jika AGENT_ID tersedia, coba Agent API
    if AGENT_ID:
        try:
            # adaptasi versi SDK yang berbeda
            conv = None
            if hasattr(client, "agents"):
                conv = client.agents.get(AGENT_ID)
            elif hasattr(client, "conversational_ai"):
                conv = client.conversational_ai.create(agent_id=AGENT_ID)
            else:
                raise Exception("Agent API client not supported")

            # Kirim user message
            response = conv.send_message(text=text)
            # Ambil audio atau teks
            audio_path = None
            if hasattr(response, "audio"):
                audio_data = response.audio
                fn = f"agent_{os.urandom(8).hex()}.mp3"
                fp = os.path.join("temp_audio", fn)
                with open(fp, "wb") as f:
                    f.write(audio_data)
                audio_path = fp

            return {"success": True, "response_text": getattr(response, "text", ""), "audio_path": audio_path, "method": "agent"}

        except ApiError as e:
            # jika API agent gagal, fallback ke TTS
            return {"success": False, "error": "Agent API error", "details": str(e)}
        except Exception as e:
            return {"success": False, "error": "Agent logic error", "details": str(e)}

    # Fallback ke TTS
    try:
        if not VOICE_ID:
            return {"success": False, "error": "Missing ELEVENLABS_VOICE_ID"}

        audio_gen = client.text_to_speech.convert(
            voice_id=VOICE_ID,
            text=text,
            model_id="eleven_multilingual_v2",
            output_format="mp3_44100_128"
        )

        fn = f"tts_{os.urandom(8).hex()}.mp3"
        fp = os.path.join("temp_audio", fn)
        os.makedirs(os.path.dirname(fp), exist_ok=True)

        with open(fp, "wb") as f:
            for chunk in audio_gen:
                if chunk:
                    f.write(chunk)

        return {"success": True, "response_text": text, "audio_path": fp, "method": "tts"}

    except ApiError as e:
        return {"success": False, "error": "TTS API error", "details": str(e)}
    except Exception as e:
        return {"success": False, "error": "Unknown error", "traceback": traceback.format_exc()}

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"success": False, "error": "No input provided"}))
        sys.exit(0)
    user_text = sys.argv[1]
    result = get_agent_response(user_text)
    print(json.dumps(result))
    sys.exit(0)

if __name__ == "__main__":
    main()
