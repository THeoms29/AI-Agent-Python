<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use GuzzleHttp\Client;


class AIAgentController extends Controller
{
    public function recognizeAudio(Request $request)
{
    $request->validate(['audio' => 'required|file|mimes:mp3,wav,ogg,mp4|max:51200']);

    $stored = $request->file('audio')->store('uploads', 'public');
    $fullPath = storage_path('app/public/' . $stored);

    // Try Python STT helper first (optional)
    $pythonExe = base_path('python_scripts/venv/Scripts/python.exe');
    $script = base_path('python_scripts/stt.py');

    if (file_exists($pythonExe) && file_exists($script)) {
        $proc = new Process([$pythonExe, $script, $fullPath]);
        $proc->setTimeout(300);
        $proc->setEnv(['ELEVENLABS_API_KEY' => env('ELEVENLABS_API_KEY')]);
        $proc->run();
        Log::info('STT python stdout: ' . $proc->getOutput());
        Log::error('STT python stderr: ' . $proc->getErrorOutput());
        if ($proc->isSuccessful()) {
            $json = json_decode($proc->getOutput(), true);
            if ($json && $json['success']) {
                return response()->json(['success' => true, 'text' => $json['transcript'] ?? null, 'stored' => $stored]);
            }
            // fallback to HTTP STT below
        } else {
            Log::warning('Python STT failed; attempting HTTP STT');
        }
    }

    // Fallback: direct HTTP STT with Guzzle
    $client = new Client(['base_uri' => 'https://api.elevenlabs.io', 'timeout' => 300]);
    try {
        $resp = $client->request('POST', '/v1/speech-to-text/convert', [
            'headers' => ['xi-api-key' => env('ELEVENLABS_API_KEY')],
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => fopen($fullPath, 'rb'),
                    'filename' => basename($fullPath),
                ],
                ['name' => 'model_id', 'contents' => 'scribe_v1'],
            ],
        ]);
        $body = json_decode((string)$resp->getBody(), true);
        $text = $body['text'] ?? ($body['transcript'] ?? null);
        return response()->json(['success' => true, 'text' => $text, 'raw' => $body]);
    } catch (\Exception $e) {
        Log::error('STT HTTP error: ' . $e->getMessage());
        return response()->json(['success' => false, 'error' => 'STT failed', 'details' => $e->getMessage()], 500);
    }
}

public function agentConverse(Request $request)
{
    $request->validate(['text' => 'required|string']);
    $text = $request->input('text');

    $agentId = env('ELEVENLABS_AGENT_ID');
    if (!$agentId) return response()->json(['success' => false, 'error' => 'Missing ELEVENLABS_AGENT_ID'], 500);

    $client = new Client(['base_uri' => 'https://api.elevenlabs.io', 'timeout' => 60]);
    try {
        $payload = ['messages' => [['role' => 'user', 'content' => $text]]];
        $resp = $client->post("/v1/convai/agents/{$agentId}/simulate-conversation", [
            'headers' => ['xi-api-key' => env('ELEVENLABS_API_KEY'), 'Content-Type' => 'application/json'],
            'json' => $payload,
        ]);
        $j = json_decode((string)$resp->getBody(), true);
        // heuristic to extract assistant response
        $agentText = null;
        if (isset($j['messages']) && is_array($j['messages'])) {
            foreach (array_reverse($j['messages']) as $m) {
                if (in_array($m['role'] ?? '', ['assistant','agent','system'])) {
                    $agentText = $m['content'] ?? ($m['text'] ?? $agentText);
                    break;
                }
            }
        }
        if (!$agentText) $agentText = $j['response'] ?? $j['text'] ?? json_encode($j);
        return response()->json(['success' => true, 'text' => $agentText, 'raw' => $j]);
    } catch (\Exception $e) {
        Log::error('Agent HTTP error: ' . $e->getMessage());
        return response()->json(['success' => false, 'error' => 'agent_error', 'details' => $e->getMessage()], 500);
    }
}

public function ttsViaPhp(Request $request)
{
    $request->validate(['text' => 'required|string']);
    $text = $request->input('text');
    $voiceId = env('ELEVENLABS_VOICE_ID');
    if (!$voiceId) return response()->json(['success' => false, 'error' => 'Missing ELEVENLABS_VOICE_ID'], 500);

    $filename = 'ai_audio_' . time() . '_' . substr(sha1($text),0,8) . '.mp3';
    $savePath = storage_path('app/public/ai_audio/' . $filename);
    @mkdir(dirname($savePath), 0755, true);

    $client = new Client(['base_uri' => 'https://api.elevenlabs.io', 'timeout' => 120]);
    try {
        $resp = $client->post("/v1/text-to-speech/{$voiceId}", [
            'headers' => ['xi-api-key' => env('ELEVENLABS_API_KEY'), 'Content-Type' => 'application/json'],
            'json' => ['text' => $text],
            'sink' => $savePath,
        ]);
        if ($resp->getStatusCode() === 200) {
            $publicUrl = asset('storage/ai_audio/' . $filename);
            return response()->json(['success' => true, 'audio_url' => $publicUrl, 'path' => $savePath]);
        }
        return response()->json(['success' => false, 'status' => $resp->getStatusCode(), 'body' => (string)$resp->getBody()], 500);
    } catch (\Exception $e) {
        Log::error('TTS HTTP error: ' . $e->getMessage());
        return response()->json(['success' => false, 'error' => 'tts_error', 'details' => $e->getMessage()], 500);
    }
}

public function converseFull(Request $request)
{
    // accept audio or text
    if ($request->hasFile('audio')) {
        // call recognizeAudio and get the text
        $sttResponse = $this->recognizeAudio($request);
        $sttJson = json_decode($sttResponse->getContent(), true);
        if (!$sttJson['success']) return $sttResponse;
        $text = $sttJson['text'];
    } else {
        $request->validate(['text' => 'required|string']);
        $text = $request->input('text');
    }

    // agent
    $agentResp = $this->agentConverse(new Request(['text' => $text]));
    $agentJson = json_decode($agentResp->getContent(), true);
    if (!$agentJson['success']) return $agentResp;
    $agentText = $agentJson['text'];

    // tts
    $ttsResp = $this->ttsViaPhp(new Request(['text' => $agentText]));
    return $ttsResp;
}
}
