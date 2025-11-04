# Awal file
import os
import sys
import json
import traceback
import requests

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

"""
Catatan:
- Kita tetap gunakan SDK ElevenLabs untuk TTS streaming saja (stabil).
- Untuk Agents, kita panggil endpoint HTTP simulate-conversation langsung,
  karena skema SDK bervariasi antar versi.
"""
try:
    from elevenlabs.client import ElevenLabs
    from elevenlabs.core.api_error import ApiError
except ImportError:
    ElevenLabs = None
    ApiError = Exception

def _extract_agent_text(resp_json: dict) -> str:
    """Ambil teks balasan dari berbagai bentuk response ElevenLabs."""
    if not isinstance(resp_json, dict):
        return ""
    # Structured messages
    messages = resp_json.get("messages")
    if isinstance(messages, list):
        for m in reversed(messages):
            role = (m or {}).get("role")
            if role in ("assistant", "agent", "system", "bot"):
                # vektor kemungkinan: content -> list of blocks / text field
                content = (m or {}).get("content")
                if isinstance(content, list):
                    for block in content:
                        if isinstance(block, dict) and block.get("type") in ("output_text", "text"):
                            txt = block.get("text")
                            if txt:
                                return txt
                # fallback sederhana
                if isinstance(content, str) and content:
                    return content
                txt = (m or {}).get("text")
                if txt:
                    return txt
    # Flat fields
    for key in ("response", "text", "message"):
        if isinstance(resp_json.get(key), str):
            return resp_json[key]
    return ""


def _simulate_conversation_http(text: str) -> dict:
    """Panggil ElevenLabs Agents simulate-conversation dengan 2 format payload."""
    if not API_KEY:
        return {"success": False, "error": "Missing ELEVENLABS_API_KEY"}
    if not AGENT_ID:
        return {"success": False, "error": "Missing ELEVENLABS_AGENT_ID"}

    base_url = "https://api.elevenlabs.io"
    url = f"{base_url}/v1/convai/agents/{AGENT_ID}/simulate-conversation"
    headers = {
        "xi-api-key": API_KEY,
        "Content-Type": "application/json",
    }

    # Attempt 1: Format dengan simulation_specification dan messages
    payload1 = {
        "simulation_specification": {
            "messages": [
                {
                    "role": "user",
                    "content": [
                        {"type": "input_text", "text": text}
                    ]
                }
            ]
        }
    }
    r = requests.post(url, headers=headers, json=payload1, timeout=60)
    if r.status_code < 400:
        data = r.json()
        agent_text = _extract_agent_text(data)
        return {"success": True, "text": agent_text, "raw": data}

    # Attempt 2: Format dengan simulation_specification dan simulated_user_config
    payload2 = {
        "simulation_specification": {
            "simulated_user_config": {
                "messages": [
                    {
                        "role": "user",
                        "content": [
                            {"type": "input_text", "text": text}
                        ]
                    }
                ]
            }
        }
    }
    r2 = requests.post(url, headers=headers, json=payload2, timeout=60)
    if r2.status_code < 400:
        data = r2.json()
        agent_text = _extract_agent_text(data)
        return {"success": True, "text": agent_text, "raw": data}

    # Attempt 3: Format dengan simulation_specification kosong dan messages di root
    payload3 = {
        "simulation_specification": {
            "simulated_user_config": {}
        },
        "messages": [
            {
                "role": "user",
                "content": [
                    {"type": "input_text", "text": text}
                ]
            }
        ]
    }
    r3 = requests.post(url, headers=headers, json=payload3, timeout=60)
    if r3.status_code < 400:
        data = r3.json()
        agent_text = _extract_agent_text(data)
        return {"success": True, "text": agent_text, "raw": data}

    # Kegagalan, kembalikan detail dari attempt terakhir
    try:
        err_json = r3.json()
    except Exception:
        err_json = r3.text
    return {
        "success": False,
        "error": "agent_client_error",
        "status_code": r3.status_code,
        "details": err_json,
    }


def get_agent_response(text):
    if not API_KEY:
        return {"success": False, "error": "Missing ELEVENLABS_API_KEY"}
    client = ElevenLabs(api_key=API_KEY)

    # Jika AGENT_ID tersedia, gunakan HTTP simulate-conversation
    if AGENT_ID:
        agent_res = _simulate_conversation_http(text)
        if agent_res.get("success"):
            return {
                "success": True,
                "response_text": agent_res.get("text", ""),
                "audio_path": None,
                "method": "agent"
            }
        # Jika gagal, teruskan detail agar UI bisa menampilkan alasannya, lalu fallback ke TTS
        agent_error = agent_res

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
        # Sertakan error agent (jika ada) untuk konteks
        out = {"success": False, "error": "TTS API error", "details": str(e)}
        if 'agent_error' in locals():
            out["agent_fallback_details"] = agent_error
        return out
    except Exception:
        out = {"success": False, "error": "Unknown error", "traceback": traceback.format_exc()}
        if 'agent_error' in locals():
            out["agent_fallback_details"] = agent_error
        return out

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
