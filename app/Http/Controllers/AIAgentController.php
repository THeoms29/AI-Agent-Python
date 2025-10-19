<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AIAgentController extends Controller
{
    public function speak(Request $request)
    {
        // Validasi input dari pengguna
        $request->validate([
            'message' => 'required|string|max:500',
        ]);

        $message = $request->input('message');
        $pythonScriptPath = base_path('python_scripts/main.py');
        $pythonExecutable = base_path('python_scripts/venv/Scripts/python.exe');

        $process = new Process([$pythonExecutable, $pythonScriptPath, $message]);
    
         $process->setEnv(array_merge($_SERVER, [
             'PATH' => getenv('PATH') . ';' . base_path('python_scripts\venv\Scripts')
         ]));
        
        // Atur timeout yang cukup panjang jika proses AI memakan waktu
        $process->setTimeout(60); 

        try {
            $process->run();

            // Memeriksa jika proses Python berhasil dijalankan
            if (!$process->isSuccessful()) {
                Log::error('Python Process Failed: ' . $process->getErrorOutput());
                throw new ProcessFailedException($process);
            }

            // Dekode output JSON dari skrip Python
            $output = json_decode($process->getOutput(), true);

            // Penanganan error dari skrip Python
            if (isset($output['error'])) {
                Log::error('Python Script Error: ' . $output['error']);
                return response()->json(['error' => 'Terjadi kesalahan pada agen AI: ' . $output['error']], 500);
            }

            $responseText = $output['response_text'] ?? 'Tidak ada respons teks dari agen AI.';
            $audioRelativePath = $output['audio_path'] ?? null; // Path relatif dari python_scripts

            $publicAudioUrl = null;
            if ($audioRelativePath && file_exists(base_path('python_scripts/' . $audioRelativePath))) {
                // Pindahkan file audio yang dihasilkan ke storage Laravel yang dapat diakses publik
                $storagePath = 'public/ai_responses/' . basename($audioRelativePath);
                Storage::disk('local')->put($storagePath, file_get_contents(base_path('python_scripts/' . $audioRelativePath)));
                
                // Hapus file sementara dari folder python_scripts setelah dipindahkan
                unlink(base_path('python_scripts/' . $audioRelativePath));

                // Dapatkan URL publik untuk file audio
                $publicAudioUrl = Storage::url($storagePath);
            } else {
                Log::warning('File audio tidak ditemukan atau tidak dihasilkan: ' . $audioRelativePath);
            }

            return response()->json([
                'message' => 'Respon agen AI berhasil diterima.',
                'response_text' => $responseText,
                'audio_url' => $publicAudioUrl ? asset($publicAudioUrl) : null,
            ]);

        } catch (ProcessFailedException $exception) {
            $process = $exception->getProcess();
            Log::error('Process Execution Error: ' . $exception->getMessage() . "\nOutput: " . $process->getOutput() . "\nError Output: " . $process->getErrorOutput());
            return response()->json(['error' => 'Gagal menjalankan agen AI. Silakan coba lagi.'], 500);
        } catch (\Exception $e) {
            Log::error('Internal Server Error: ' . $e->getMessage());
            return response()->json(['error' => 'Terjadi kesalahan internal server. Silakan coba lagi.'], 500);
        }
    }
}