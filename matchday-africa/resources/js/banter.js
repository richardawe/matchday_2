// Full-time banter cards (partials/banter-share): choose a side and a voice; "Send to WhatsApp"
// shares the card image itself where the phone allows it, otherwise opens WhatsApp with the words.
export function start(root) {
    const cards = JSON.parse(root.dataset.banterCards || '{}');
    const link = root.dataset.banterLink;
    const img = root.querySelector('[data-banter-img]');
    const send = root.querySelector('[data-banter-send]');
    const save = root.querySelector('[data-banter-save]');
    const pressed = (attr) => root.querySelector(`[${attr}][aria-pressed="true"]`)?.getAttribute(attr);
    let side = pressed('data-banter-side') || 'home';
    let voice = pressed('data-banter-voice') || 'pcm';

    const current = () => cards[side]?.[voice];
    const render = () => {
        const card = current();
        if (!card) return;
        img.src = card.img;
        save.href = card.img;
        send.href = 'https://wa.me/?text=' + encodeURIComponent(card.text + ' ' + link);
    };
    const choose = (attr, set) => root.querySelectorAll(`[${attr}]`).forEach((button) => button.addEventListener('click', () => {
        root.querySelectorAll(`[${attr}]`).forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
        set(button.getAttribute(attr));
        render();
    }));
    choose('data-banter-side', (v) => { side = v; });
    choose('data-banter-voice', (v) => { voice = v; });

    send.addEventListener('click', async (event) => {
        const card = current();
        if (!card || !navigator.canShare) return;
        try {
            const blob = await (await fetch(card.img)).blob();
            const file = new File([blob], `${root.dataset.banterFile || 'matchday'}-${side}.png`, {type: 'image/png'});
            if (!navigator.canShare({files: [file]})) return;
            event.preventDefault();
            await navigator.share({files: [file], text: card.text + ' ' + link});
        } catch (error) {
            // Cancelled or unsupported: the link's own WhatsApp text share still works next tap.
        }
    });
}
