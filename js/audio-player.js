/**
 * Custom Audio Player
 * Steuert die benutzerdefinierten Audio-Player auf der Seite
 */

(function() {
    'use strict';

    // Alle Player initialisieren wenn DOM geladen
    document.addEventListener('DOMContentLoaded', initAudioPlayers);

    // Auch bei dynamisch geladenen Inhalten (für Inline-Editor)
    window.initAudioPlayers = initAudioPlayers;

    function initAudioPlayers() {
        const players = document.querySelectorAll('.custom-audio-player');
        players.forEach(initPlayer);
    }

    function initPlayer(playerElement) {
        // Nicht doppelt initialisieren
        if (playerElement.dataset.initialized) return;
        playerElement.dataset.initialized = 'true';

        const playerId = playerElement.dataset.playerId;
        const audio = document.getElementById(playerId);
        if (!audio) return;

        const playBtn = playerElement.querySelector('.audio-play-btn');
        const progressBar = playerElement.querySelector('.audio-progress-bar');
        const progress = playerElement.querySelector('.audio-progress');
        const handle = playerElement.querySelector('.audio-progress-handle');
        const currentTime = playerElement.querySelector('.audio-current');
        const durationDisplay = playerElement.querySelector('.audio-duration');

        // Volume Controls
        const volumeBtn = playerElement.querySelector('.audio-volume-btn');
        const volumeSlider = playerElement.querySelector('.audio-volume-slider');
        const volumeTrack = playerElement.querySelector('.audio-volume-track');
        const volumeFill = playerElement.querySelector('.audio-volume-fill');
        const volumeHandle = playerElement.querySelector('.audio-volume-handle');

        // Zeit formatieren (mm:ss)
        function formatTime(seconds) {
            if (isNaN(seconds) || !isFinite(seconds)) return '0:00';
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return mins + ':' + (secs < 10 ? '0' : '') + secs;
        }

        // Volume Icon aktualisieren
        function updateVolumeIcon() {
            playerElement.classList.remove('volume-muted', 'volume-low');
            if (audio.muted || audio.volume === 0) {
                playerElement.classList.add('volume-muted');
            } else if (audio.volume < 0.5) {
                playerElement.classList.add('volume-low');
            }
        }

        // Volume UI aktualisieren
        function updateVolumeUI() {
            const percent = audio.muted ? 0 : audio.volume * 100;
            volumeFill.style.width = percent + '%';
            volumeHandle.style.left = percent + '%';
            updateVolumeIcon();
        }

        // Dauer anzeigen wenn Metadaten geladen
        audio.addEventListener('loadedmetadata', function() {
            durationDisplay.textContent = formatTime(audio.duration);
        });

        // Falls Dauer schon bekannt (cached)
        if (audio.duration) {
            durationDisplay.textContent = formatTime(audio.duration);
        }

        // Initiale Volume UI
        updateVolumeUI();

        // Play/Pause
        playBtn.addEventListener('click', function() {
            // Andere Player pausieren
            document.querySelectorAll('.custom-audio-player.playing').forEach(function(other) {
                if (other !== playerElement) {
                    const otherId = other.dataset.playerId;
                    const otherAudio = document.getElementById(otherId);
                    if (otherAudio) {
                        otherAudio.pause();
                        other.classList.remove('playing');
                    }
                }
            });

            if (audio.paused) {
                audio.play();
                playerElement.classList.add('playing');
            } else {
                audio.pause();
                playerElement.classList.remove('playing');
            }
        });

        // Progress Update
        audio.addEventListener('timeupdate', function() {
            const percent = (audio.currentTime / audio.duration) * 100 || 0;
            progress.style.width = percent + '%';
            handle.style.left = percent + '%';
            currentTime.textContent = formatTime(audio.currentTime);
        });

        // Ende erreicht
        audio.addEventListener('ended', function() {
            playerElement.classList.remove('playing');
            progress.style.width = '0%';
            handle.style.left = '0%';
            currentTime.textContent = '0:00';
        });

        // Klick auf Progress-Bar
        let isDraggingProgress = false;

        function seek(e) {
            const rect = progressBar.getBoundingClientRect();
            const percent = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
            audio.currentTime = percent * audio.duration;
        }

        progressBar.addEventListener('mousedown', function(e) {
            isDraggingProgress = true;
            seek(e);
        });

        document.addEventListener('mousemove', function(e) {
            if (isDraggingProgress) {
                seek(e);
            }
        });

        document.addEventListener('mouseup', function() {
            isDraggingProgress = false;
        });

        // Touch-Support für Progress
        progressBar.addEventListener('touchstart', function(e) {
            isDraggingProgress = true;
            seek(e.touches[0]);
        }, { passive: true });

        document.addEventListener('touchmove', function(e) {
            if (isDraggingProgress && e.touches[0]) {
                seek(e.touches[0]);
            }
        }, { passive: true });

        document.addEventListener('touchend', function() {
            isDraggingProgress = false;
        });

        // ========== VOLUME CONTROLS ==========

        let previousVolume = 1;

        // Mute/Unmute beim Klick auf Volume-Button
        volumeBtn.addEventListener('click', function() {
            if (audio.muted || audio.volume === 0) {
                audio.muted = false;
                audio.volume = previousVolume || 0.5;
            } else {
                previousVolume = audio.volume;
                audio.muted = true;
            }
            updateVolumeUI();
        });

        // Volume Slider
        let isDraggingVolume = false;

        function setVolume(e) {
            const rect = volumeTrack.getBoundingClientRect();
            const percent = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
            audio.volume = percent;
            audio.muted = false;
            updateVolumeUI();
        }

        volumeTrack.addEventListener('mousedown', function(e) {
            isDraggingVolume = true;
            volumeSlider.classList.add('active');
            setVolume(e);
        });

        document.addEventListener('mousemove', function(e) {
            if (isDraggingVolume) {
                setVolume(e);
            }
        });

        document.addEventListener('mouseup', function() {
            if (isDraggingVolume) {
                isDraggingVolume = false;
                setTimeout(function() {
                    volumeSlider.classList.remove('active');
                }, 300);
            }
        });

        // Touch-Support für Volume
        volumeTrack.addEventListener('touchstart', function(e) {
            isDraggingVolume = true;
            volumeSlider.classList.add('active');
            setVolume(e.touches[0]);
        }, { passive: true });

        document.addEventListener('touchmove', function(e) {
            if (isDraggingVolume && e.touches[0]) {
                setVolume(e.touches[0]);
            }
        }, { passive: true });

        document.addEventListener('touchend', function() {
            if (isDraggingVolume) {
                isDraggingVolume = false;
                setTimeout(function() {
                    volumeSlider.classList.remove('active');
                }, 300);
            }
        });
    }
})();
