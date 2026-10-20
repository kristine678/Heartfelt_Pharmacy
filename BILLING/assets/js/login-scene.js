// FIX (Bug 2): login canvas scene — previously this code was rendered TWICE in login.php
// (the second copy without a <script> tag printed raw JS text onto the page). It now lives in
// this ONE shared file, loaded by login.php (and reusable by any login-style page).
(function () {
    var mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    function applyMotionState() {
        document.body.classList.toggle('reduced-motion', mediaQuery.matches);
    }
    applyMotionState();
    if (mediaQuery.addEventListener) {
        mediaQuery.addEventListener('change', applyMotionState);
    } else if (mediaQuery.addListener) {
        mediaQuery.addListener(applyMotionState);
    }
    document.addEventListener('visibilitychange', function () {
        document.body.classList.toggle('paused', document.hidden);
    });

    var canvas = document.querySelector('.bg-scene .bg-canvas');
    if (!canvas) {
        return;
    }
    var context = canvas.getContext('2d');
    var gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
    if (!gl) {
        document.body.classList.add('no-webgl');
        return;
    }

    var reduced = mediaQuery.matches;
    var width = 0;
    var height = 0;
    var orbitAngle = 0;
    var pointerX = 0;
    var pointerY = 0;
    var requestId = null;

    function resizeCanvas() {
        var ratio = Math.min(window.devicePixelRatio || 1, 2);
        width = window.innerWidth;
        height = window.innerHeight;
        canvas.width = Math.max(1, Math.floor(width * ratio));
        canvas.height = Math.max(1, Math.floor(height * ratio));
        canvas.style.width = width + 'px';
        canvas.style.height = height + 'px';
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
    }

    function drawMedicine(x, y, scale, tint) {
        var s = scale;
        context.save();
        context.translate(x, y);
        context.fillStyle = tint;
        context.strokeStyle = 'rgba(255,255,255,0.75)';
        context.lineWidth = 2;

        context.beginPath();
        context.roundRect(-18 * s, -10 * s, 36 * s, 20 * s, 10 * s);
        context.fill();
        context.stroke();

        context.beginPath();
        context.roundRect(-5 * s, -18 * s, 10 * s, 36 * s, 5 * s);
        context.fill();
        context.stroke();

        context.beginPath();
        context.ellipse(0, 0, 12 * s, 8 * s, 0, 0, Math.PI * 2);
        context.fillStyle = 'rgba(255,255,255,0.4)';
        context.fill();
        context.restore();
    }

    function renderFrame() {
        if (document.hidden || mediaQuery.matches) {
            context.clearRect(0, 0, width, height);
            return;
        }

        context.clearRect(0, 0, width, height);
        context.fillStyle = 'rgba(255, 183, 213, 0.10)';
        context.fillRect(0, 0, width, height);

        var centerX = width * 0.5 + pointerX * 18;
        var centerY = height * 0.5 + pointerY * 18;
        var globeRadius = Math.min(width, height) * 0.36;

        var globe = context.createRadialGradient(
            centerX - globeRadius * 0.26,
            centerY - globeRadius * 0.28,
            globeRadius * 0.18,
            centerX,
            centerY,
            globeRadius * 1.08
        );
        globe.addColorStop(0, 'rgba(255,245,249,0.96)');
        globe.addColorStop(0.18, 'rgba(255,209,227,0.96)');
        globe.addColorStop(0.46, 'rgba(255,164,200,0.9)');
        globe.addColorStop(0.72, 'rgba(255,120,177,0.72)');
        globe.addColorStop(1, 'rgba(234,92,154,0.28)');

        context.beginPath();
        context.arc(centerX, centerY, globeRadius, 0, Math.PI * 2);
        context.fillStyle = globe;
        context.shadowColor = 'rgba(255, 113, 174, 0.34)';
        context.shadowBlur = 62;
        context.fill();
        context.shadowBlur = 0;

        context.beginPath();
        context.arc(centerX, centerY, globeRadius, 0, Math.PI * 2);
        context.lineWidth = 1.4;
        context.strokeStyle = 'rgba(255,255,255,0.45)';
        context.stroke();

        for (var i = 0; i < 5; i++) {
            var shift = i * 36;
            context.beginPath();
            context.ellipse(centerX, centerY + 8, globeRadius * 0.85, globeRadius * 0.42, ((i % 2) ? -0.75 : 0.75) + (orbitAngle * 0.02), 0, Math.PI * 2);
            context.strokeStyle = 'rgba(255,255,255,0.18)';
            context.lineWidth = 1;
            context.stroke();

            context.beginPath();
            context.ellipse(centerX, centerY, globeRadius * 0.9, globeRadius * 0.62, (Math.PI / 12) + (shift / 180), 0, Math.PI * 2);
            context.strokeStyle = 'rgba(255,255,255,0.12)';
            context.stroke();
        }

        var medicines = [
            { angle: orbitAngle * 0.9 + 0.3, radius: globeRadius * 1.22, scale: 1.5, tint: 'rgba(255,245,249,0.94)' },
            { angle: orbitAngle * 1.2 + 1.5, radius: globeRadius * 1.38, scale: 1.35, tint: 'rgba(255,195,220,0.92)' },
            { angle: orbitAngle * 1.5 + 2.3, radius: globeRadius * 1.54, scale: 1.2, tint: 'rgba(255,140,190,0.9)' },
            { angle: orbitAngle * 1.1 + 3.7, radius: globeRadius * 1.68, scale: 1.3, tint: 'rgba(255,240,248,0.88)' },
            { angle: orbitAngle * 1.4 + 4.8, radius: globeRadius * 1.82, scale: 1.05, tint: 'rgba(255,202,228,0.9)' },
            { angle: orbitAngle * 1.7 + 5.6, radius: globeRadius * 1.94, scale: 1.15, tint: 'rgba(255,180,214,0.86)' }
        ];

        medicines.forEach(function (item) {
            var tx = centerX + Math.cos(item.angle) * item.radius;
            var ty = centerY + Math.sin(item.angle) * item.radius * 0.82;
            drawMedicine(tx, ty, item.scale, item.tint);
        });

        for (var j = 0; j < 24; j++) {
            var sparkleX = (j * 97 + orbitAngle * 1.5) % (width + 40) - 20;
            var sparkleY = ((j * 53 + orbitAngle * 2.1) % (height + 60)) - 30;
            context.beginPath();
            context.arc(sparkleX, sparkleY, (j % 4) + 1.2, 0, Math.PI * 2);
            context.fillStyle = ['rgba(255,255,255,0.7)', 'rgba(255,214,232,0.68)', 'rgba(255,228,188,0.55)', 'rgba(255,184,210,0.7)'][j % 4];
            context.fill();
        }

        if ((Math.sin(orbitAngle * 0.9) + 1) * 0.5 > 0.96) {
            context.beginPath();
            context.rect(centerX - globeRadius * 1.2, centerY - globeRadius * 1.2, globeRadius * 2.4, globeRadius * 2.4);
            context.strokeStyle = 'rgba(255,255,255,0.18)';
            context.lineWidth = 1;
            context.stroke();
        }

        if (!reduced) {
            orbitAngle += 0.014;
        }

        requestId = requestAnimationFrame(renderFrame);
    }

    function startScene() {
        resizeCanvas();
        requestId = requestAnimationFrame(renderFrame);
    }

    window.addEventListener('pointermove', function (event) {
        pointerX = (event.clientX / width - 0.5) * 2;
        pointerY = (event.clientY / height - 0.5) * 2;
    });
    window.addEventListener('resize', resizeCanvas);
    startScene();
    canvas.style.opacity = '1';
})();
