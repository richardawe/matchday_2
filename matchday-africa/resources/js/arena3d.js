// The full arena for capable devices: the two crest-free armies standing in 3D under
// team-coloured light, a Kente-patterned floor fading into fog, and gold dust rising.
// Loaded on demand by arena.js; if anything fails, the still/GSAP arena stays as it is.
import * as THREE from 'three';
import { gsap } from 'gsap';

export async function mount(arena) {
    const canvas = document.createElement('canvas');
    canvas.className = 'md-arena-gl';
    canvas.setAttribute('aria-hidden', 'true');
    const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: false, powerPreference: 'high-performance' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 1.75));
    renderer.setClearColor(0x050505);
    renderer.outputColorSpace = THREE.SRGBColorSpace;

    const scene = new THREE.Scene();
    scene.fog = new THREE.Fog(0x050505, 6, 14);
    const camera = new THREE.PerspectiveCamera(40, 1, 0.1, 40);
    camera.position.set(0, 1.4, 6.2);

    const loader = new THREE.TextureLoader();
    const texture = (url) => new Promise((resolve, reject) => loader.load(url, (t) => { t.colorSpace = THREE.SRGBColorSpace; resolve(t); }, undefined, reject));
    const homeColour = new THREE.Color(arena.dataset.homeColour);
    const awayColour = new THREE.Color(arena.dataset.awayColour);

    // Soft additive glow discs behind each army, in the club's colour.
    const glowTexture = (() => {
        const c = document.createElement('canvas'); c.width = c.height = 256;
        const g = c.getContext('2d').createRadialGradient(128, 128, 0, 128, 128, 128);
        g.addColorStop(0, 'rgba(255,255,255,1)'); g.addColorStop(0.4, 'rgba(255,255,255,.35)'); g.addColorStop(1, 'rgba(255,255,255,0)');
        const ctx = c.getContext('2d'); ctx.fillStyle = g; ctx.fillRect(0, 0, 256, 256);
        return new THREE.CanvasTexture(c);
    })();
    const glows = [homeColour, awayColour].map((colour, i) => {
        const sprite = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTexture, color: colour, transparent: true, opacity: 0.55, blending: THREE.AdditiveBlending, depthWrite: false }));
        sprite.scale.set(6, 7, 1);
        sprite.position.set(i ? 2.5 : -2.5, 2, -1.2);
        scene.add(sprite);
        return sprite;
    });

    // The armies: cut-out textures on upright planes, angled towards each other.
    const [homeArt, awayArt] = await Promise.all([arena.dataset.homeArt, arena.dataset.awayArt].map((url) => (url ? texture(url) : Promise.resolve(null))));
    const armies = [homeArt, awayArt].map((art, i) => {
        if (!art) return null;
        const aspect = art.image.width / art.image.height;
        const height = 4.3;
        const plane = new THREE.Mesh(new THREE.PlaneGeometry(height * aspect, height), new THREE.MeshBasicMaterial({ map: art, transparent: true, alphaTest: 0.04, side: THREE.DoubleSide }));
        plane.position.set(i ? 2.25 : -2.25, height / 2 - 0.05, 0);
        plane.rotation.y = i ? -0.32 : 0.32;
        if (!i) plane.scale.x = -1;
        scene.add(plane);
        return plane;
    });

    // Kente-pattern floor in both clubs' colours, lost in the fog.
    const floorTexture = (() => {
        const c = document.createElement('canvas'); c.width = 512; c.height = 32;
        const ctx = c.getContext('2d');
        const bands = [[arena.dataset.homeColour, 80], ['#d2a052', 20], [arena.dataset.awayColour, 80], ['#070807', 16], ['#d2a052', 20], ['#070807', 16]];
        let x = 0;
        while (x < 512) for (const [colour, width] of bands) { ctx.fillStyle = colour; ctx.fillRect(x, 0, width, 32); x += width; }
        const t = new THREE.CanvasTexture(c); t.wrapS = t.wrapT = THREE.RepeatWrapping; t.repeat.set(4, 30); t.colorSpace = THREE.SRGBColorSpace;
        return t;
    })();
    const floor = new THREE.Mesh(new THREE.PlaneGeometry(30, 30), new THREE.MeshBasicMaterial({ map: floorTexture, transparent: true, opacity: 0.32 }));
    floor.rotation.x = -Math.PI / 2;
    scene.add(floor);

    // Gold dust.
    const count = 500;
    const positions = new Float32Array(count * 3);
    for (let i = 0; i < count; i++) positions.set([(Math.random() - 0.5) * 12, Math.random() * 6, (Math.random() - 0.5) * 6 - 1], i * 3);
    const dustGeometry = new THREE.BufferGeometry();
    dustGeometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    const dust = new THREE.Points(dustGeometry, new THREE.PointsMaterial({ color: 0xedc781, size: 0.035, transparent: true, opacity: 0.7, blending: THREE.AdditiveBlending, depthWrite: false }));
    scene.add(dust);

    const resize = () => {
        const { clientWidth: w, clientHeight: h } = arena;
        renderer.setSize(w, h, false);
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
    };
    resize();
    window.addEventListener('resize', resize);
    arena.querySelector('.md-arena-stage').after(canvas);
    arena.classList.add('is-3d');

    // Entrance, then a slow drift; the camera follows the pointer a little.
    armies.forEach((army, i) => army && gsap.from(army.position, { x: i ? 5 : -5, duration: 2, ease: 'expo.out' }));
    glows.forEach((glow) => gsap.from(glow.material, { opacity: 0, duration: 2.4, ease: 'power2.out' }));
    const pointer = { x: 0, y: 0 };
    arena.addEventListener('pointermove', (e) => { pointer.x = e.clientX / window.innerWidth - 0.5; pointer.y = e.clientY / window.innerHeight - 0.5; });
    arena.addEventListener('arena:goal', ({ detail }) => {
        const glow = glows[detail.side === 'away' ? 1 : 0];
        gsap.fromTo(glow.material, { opacity: 1.2 }, { opacity: 0.55, duration: 2.5, ease: 'power2.out' });
        gsap.fromTo(glow.scale, { x: 9, y: 10 }, { x: 6, y: 7, duration: 2.5, ease: 'power2.out' });
    });

    const clock = new THREE.Clock();
    let running = true;
    const render = () => {
        if (!running) return;
        const t = clock.getElapsedTime();
        const p = dustGeometry.attributes.position;
        for (let i = 0; i < count; i++) { let y = p.getY(i) + 0.004; if (y > 6) y = 0; p.setY(i, y); }
        p.needsUpdate = true;
        camera.position.x += (pointer.x * 0.8 - camera.position.x) * 0.04;
        camera.position.y += (1.4 - pointer.y * 0.4 - camera.position.y) * 0.04;
        camera.lookAt(0, 1.9, 0);
        glows.forEach((glow, i) => { glow.material.opacity += (0.5 + Math.sin(t * 1.3 + i * 2) * 0.08 - glow.material.opacity) * 0.02; });
        renderer.render(scene, camera);
        requestAnimationFrame(render);
    };
    new IntersectionObserver(([entry]) => {
        const visible = entry.isIntersecting && !document.hidden;
        if (visible && !running) { running = true; requestAnimationFrame(render); } else if (!visible) running = false;
    }).observe(arena);
    requestAnimationFrame(render);
}
