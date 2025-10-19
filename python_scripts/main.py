import os
import sys
import json
import asyncio
from dotenv import load_dotenv

# CRITICAL FIX untuk Windows asyncio error
if sys.platform == 'win32':
    asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())

from elevenlabs.client import ElevenLabs

# Load environment variables
load_dotenv()

ELEVENLABS_API_KEY = os.getenv("ELEVENLABS_API_KEY")
ELEVENLABS_AGENT_ID = os.getenv("ELEVENLABS_AGENT_ID")

# Pastikan folder untuk menyimpan audio ada
AUDIO_OUTPUT_DIR = "temp_audio"
os.makedirs(AUDIO_OUTPUT_DIR, exist_ok=True)

def get_agent_response(user_input):
    """
    Fungsi utama untuk berkomunikasi dengan ElevenLabs
    """
    try:
        # Validasi environment variables
        if not ELEVENLABS_API_KEY:
            return {
                "success": False,
                "error": "ELEVENLABS_API_KEY tidak ditemukan di .env"
            }
        
        # Inisialisasi client
        client = ElevenLabs(api_key=ELEVENLABS_API_KEY)
        
        if ELEVENLABS_AGENT_ID:
            try:
                return use_conversational_ai(client, user_input)
            except Exception as e:
                print(f"Conversational AI failed: {str(e)}", file=sys.stderr)
                print("Falling back to Text-to-Speech...", file=sys.stderr)
        
        # Fallback ke Text-to-Speech
        return use_text_to_speech(client, user_input)
        
    except Exception as e:
        import traceback
        error_details = traceback.format_exc()
        
        print(f"ERROR: {str(e)}", file=sys.stderr)
        print(error_details, file=sys.stderr)
        
        return {
            "success": False,
            "error": str(e),
            "error_type": type(e).__name__,
            "traceback": error_details
        }

def use_conversational_ai(client, user_input):
    """
    Menggunakan ElevenLabs Conversational AI Agent
    """
    try:
        response_text = ""
        audio_data = b""
        
        # Create conversation with agent
        conversation = client.conversational_ai.get_or_create_conversation(
            agent_id=ELEVENLABS_AGENT_ID
        )
        
        conversation_id = conversation.conversation_id
        
        # Send message and collect response
        response = client.conversational_ai.send_message(
            conversation_id=conversation_id,
            text=user_input
        )
        
        # Stream and collect data
        for event in response:
            # Collect text
            if hasattr(event, 'message') and hasattr(event.message, 'text'):
                response_text += event.message.text
            elif hasattr(event, 'text'):
                response_text += event.text
            
            # Collect audio
            if hasattr(event, 'audio') and event.audio:
                audio_data += event.audio
        
        # Save audio if available
        audio_path = None
        if audio_data:
            audio_filename = f"response_{os.urandom(8).hex()}.mp3"
            audio_path = os.path.join(AUDIO_OUTPUT_DIR, audio_filename)
            
            with open(audio_path, "wb") as f:
                f.write(audio_data)
        
        return {
            "success": True,
            "response_text": response_text if response_text else "Response received from agent",
            "audio_path": audio_path,
            "method": "conversational_ai",
            "conversation_id": conversation_id
        }
        
    except Exception as e:
        raise e

def use_text_to_speech(client, user_input):
    try:
        # Generate response text sederhana
        response_text = f"Anda berkata: {user_input}. Bagaimana saya bisa membantu Anda hari ini?"
        
        audio_generator = client.text_to_speech.convert(
            voice_id="xChNffR8mWkGIrdSUYsg", 
            text=response_text,
            model_id="eleven_multilingual_v2",
            output_format="mp3_44100_128"
        )
        
        # Simpan audio
        audio_filename = f"response_{os.urandom(8).hex()}.mp3"
        audio_path = os.path.join(AUDIO_OUTPUT_DIR, audio_filename)
        
        # Tulis audio chunks ke file
        with open(audio_path, "wb") as f:
            for chunk in audio_generator:
                if chunk:
                    f.write(chunk)
        
        return {
            "success": True,
            "response_text": response_text,
            "audio_path": audio_path,
            "method": "text_to_speech",
            "note": "Using TTS. For conversational AI, ensure ELEVENLABS_AGENT_ID is set."
        }
        
    except Exception as e:
        import traceback
        return {
            "success": False,
            "error": f"TTS method failed: {str(e)}",
            "error_type": type(e).__name__,
            "traceback": traceback.format_exc()
        }

def test_connection():
    """
    Fungsi untuk testing koneksi ke ElevenLabs API
    """
    try:
        if not ELEVENLABS_API_KEY:
            return {
                "success": False,
                "error": "ELEVENLABS_API_KEY not found in .env"
            }
        
        client = ElevenLabs(api_key=ELEVENLABS_API_KEY)
        
        # Test dengan mengambil daftar voices
        voices_response = client.voices.get_all()
        
        voices_count = 0
        if hasattr(voices_response, 'voices'):
            voices_count = len(voices_response.voices)
        
        return {
            "success": True,
            "message": "Connection successful",
            "sdk_version": "2.18.0",
            "api_key_set": bool(ELEVENLABS_API_KEY),
            "agent_id_set": bool(ELEVENLABS_AGENT_ID),
            "voices_count": len(voices_response.voices) if hasattr(voices_response, 'voices') else 0,
            "available_voices": voices_count
        }
        
    except Exception as e:
        import traceback
        return {
            "success": False,
            "error": str(e),
            "error_type": type(e).__name__,
            "traceback": traceback.format_exc()
        }

if __name__ == "__main__":
    try:
        # Jika dipanggil dengan argumen 'test'
        if len(sys.argv) > 1 and sys.argv[1] == "test":
            result = test_connection()
            print(json.dumps(result, ensure_ascii=False, indent=2))
        
        # Jika dipanggil dengan input message
        elif len(sys.argv) > 1:
            user_input_from_laravel = sys.argv[1]
            result = get_agent_response(user_input_from_laravel)
            print(json.dumps(result, ensure_ascii=False, indent=2))
        
        else:
            print(json.dumps({
                "success": False, 
                "error": "No input provided. Usage: python main.py 'your message' OR python main.py test"
            }, ensure_ascii=False))
            
    except KeyboardInterrupt:
        print(json.dumps({"success": False, "error": "Interrupted by user"}, ensure_ascii=False))
    except Exception as e:
        import traceback
        print(json.dumps({
            "success": False,
            "error": str(e),
            "error_type": type(e).__name__,
            "traceback": traceback.format_exc()
        }, ensure_ascii=False))