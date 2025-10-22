import os, sys, json, traceback
from dotenv import load_dotenv
import requests

load_dotenv()
API_KEY = os.getenv("ELEVENLABS_API_KEY")

def speech_to_text(file_path, model_id="scribe_v1"):
    url = "https://api.elevenlabs.io/v1/speech-to-text/convert"
    headers = {"xi-api-key": API_KEY}
    files = {'file': open(file_path, 'rb')}
    data = {'model_id': model_id}
    r = requests.post(url, headers=headers, data=data, files=files, timeout=300)
    r.raise_for_status()
    return r.json()

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(json.dumps({"success": False, "error": "Usage: stt.py <file>"})); sys.exit(0)
    f = sys.argv[1]
    try:
        res = speech_to_text(f)
        print(json.dumps({"success": True, "transcript": res.get("text"), "raw": res}))
    except Exception as e:
        print(json.dumps({"success": False, "error": str(e), "trace": traceback.format_exc()}))
        sys.exit(1)