<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Agent Voice Assistant</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
    <style>
        /* Style untuk indikator merekam */
        .recording {
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="card p-4 shadow-sm">
            <h1 class="mb-4 text-center">AI Agent Voice Assistant </h1>

            <div class="text-center mb-3">
                <button id="recordButton" class="btn btn-danger btn-lg rounded-circle" style="width: 80px; height: 80px;">
                    🎤
                </button>
                <p id="status" class="mt-2 text-muted">Tekan untuk berbicara</p>
            </div>

            <div id="responseContainer" class="mt-4 p-3 bg-white border rounded">
                <p><strong>Anda berkata:</strong> <span id="userInputText" class="text-primary"></span></p>
                <hr>
                <p><strong>Respons Agen:</strong> <span id="responseText"></span></p>
                <div id="audioPlayerContainer" class="mt-2">
                    </div>
                <div id="errorMessage" class="text-danger mt-2"></div>
            </div>
        </div>
    </div>

    <script>
        // Cek dukungan browser untuk Web Speech API
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        let recognition;

        if (SpeechRecognition) {
            recognition = new SpeechRecognition();
            recognition.continuous = false; // Hanya menangkap satu kalimat
            recognition.lang = 'id-ID';     // Set bahasa ke Indonesia

            const recordButton = document.getElementById('recordButton');
            const statusDiv = document.getElementById('status');
            const userInputTextSpan = document.getElementById('userInputText');

            // Ketika tombol rekam diklik
            recordButton.addEventListener('click', () => {
                if (recordButton.classList.contains('recording')) {
                    recognition.stop();
                } else {
                    recognition.start();
                }
            });

            // Saat mulai merekam
            recognition.onstart = () => {
                statusDiv.textContent = 'Mendengarkan...';
                recordButton.classList.add('recording');
                recordButton.innerHTML = '...';
            };

            // Saat berhenti merekam
            recognition.onend = () => {
                statusDiv.textContent = 'Tekan untuk berbicara';
                recordButton.classList.remove('recording');
                recordButton.innerHTML = '🎤';
            };

            // Saat mendapatkan hasil transkripsi
            recognition.onresult = (event) => {
                const transcript = event.results[0][0].transcript;
                userInputTextSpan.textContent = transcript;
                
                // Panggil fungsi untuk mengirim teks ke Laravel
                sendTextToAI(transcript); 
            };

            // Penanganan error
            recognition.onerror = (event) => {
                console.error("Speech recognition error", event.error);
                statusDiv.textContent = `Error: ${event.error}`;
            };

        } else {
            // Jika browser tidak mendukung
            document.getElementById('status').textContent = "Maaf, browser Anda tidak mendukung fitur input suara.";
            document.getElementById('recordButton').disabled = true;
        }


        // **FUNGSI INI TETAP SAMA SEPERTI SEBELUMNYA**
        // Fungsi untuk mengirim teks ke backend Laravel
        async function sendTextToAI(message) {
            const responseTextSpan = document.getElementById('responseText');
            const audioPlayerContainer = document.getElementById('audioPlayerContainer');
            const errorMessageDiv = document.getElementById('errorMessage');
            const statusDiv = document.getElementById('status');

            // Reset tampilan
            responseTextSpan.textContent = '';
            audioPlayerContainer.innerHTML = '';
            errorMessageDiv.textContent = '';
            statusDiv.textContent = 'Agen sedang berpikir...';

            try {
                const response = await axios.post('/api/ai-agent/converse', {
                    text: message
                });

                const data = response.data;
                
                // Cek apakah response sukses
                if (data.success) {
                    // Tampilkan teks response dari agent jika tersedia
                    if (data.response_text) {
                        responseTextSpan.textContent = data.response_text;
                    }
                    
                    // Tampilkan audio jika tersedia
                    if (data.audio_url) {
                        audioPlayerContainer.innerHTML = `<audio controls autoplay><source src="${data.audio_url}" type="audio/mpeg"></audio>`;
                    } else if (data.text_truncated || data.tts_error) {
                        // Jika teks terlalu panjang atau TTS gagal, tampilkan pesan informatif
                        if (data.message) {
                            audioPlayerContainer.innerHTML = `<p class="text-warning"><small>ℹ ${data.message}</small></p>`;
                        } else {
                            audioPlayerContainer.innerHTML = `<p class="text-warning"><small>ℹ Audio tidak tersedia karena teks terlalu panjang. Teks tetap ditampilkan di atas.</small></p>`;
                        }
                    } else {
                        errorMessageDiv.textContent = 'Error: File audio tidak diterima.';
                    }
                    statusDiv.textContent = 'Tekan untuk berbicara';
                } else {
                    // Handle error dari backend
                    let errorMsg = data.message || data.error || 'Terjadi kesalahan saat memproses permintaan.';
                    
                    // Tampilkan detail jika ada
                    if (data.details) {
                        if (typeof data.details === 'object') {
                            console.error('Error details:', data.details);
                            // Coba ekstrak pesan yang lebih spesifik dari details
                            if (data.details.detail) {
                                errorMsg = data.details.detail.message || data.details.detail || errorMsg;
                            } else if (data.details.message) {
                                errorMsg = data.details.message;
                            }
                        }
                    }
                    
                    errorMessageDiv.textContent = 'Error: ' + errorMsg;
                    statusDiv.textContent = 'Tekan untuk berbicara';
                }

            } catch (error) {
                console.error('Error:', error);
                let errorMsg = 'Terjadi kesalahan saat memproses permintaan.';
                
                // Handle berbagai jenis error response
                if (error.response) {
                    // Server merespons dengan status error
                    const errorData = error.response.data;
                    if (errorData) {
                        if (typeof errorData === 'string') {
                            errorMsg = errorData;
                        } else if (errorData.message) {
                            // Prioritaskan message field
                            errorMsg = errorData.message;
                        } else if (errorData.error) {
                            errorMsg = errorData.error;
                        } else if (errorData.errors) {
                            // Laravel validation errors
                            const validationErrors = Object.values(errorData.errors).flat();
                            errorMsg = validationErrors.join(', ');
                        } else if (error.response.status === 422) {
                            errorMsg = 'Validasi gagal. Pastikan data yang dikirim benar.';
                        } else if (error.response.status === 400) {
                            errorMsg = errorData.message || 'Bad Request. Periksa konfigurasi API ElevenLabs di file .env (ELEVENLABS_API_KEY dan ELEVENLABS_AGENT_ID).';
                        }
                        
                        // Log detail error untuk debugging
                        if (errorData.details) {
                            console.error('Error details:', errorData.details);
                        }
                        if (errorData.status_code) {
                            console.error('Status code:', errorData.status_code);
                        }
                    }
                } else if (error.request) {
                    // Request dibuat tapi tidak ada response
                    errorMsg = 'Tidak ada response dari server. Periksa koneksi internet Anda.';
                }
                
                errorMessageDiv.textContent = 'Error: ' + errorMsg;
                statusDiv.textContent = 'Tekan untuk berbicara';
            }
        }
    </script>
</body>
</html>