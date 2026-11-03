// Which picture a warrior gets. The classic page keeps its warrior portraits; the cartoon look
// (SITE_STYLE=cartoon or ?style=cartoon, body.war-cartoon) uses the crest-free statues the reels
// use, cut out with an ink line (public/war/army/<art>-solid.webp, scripts/build-armies.mjs).
export const CARTOON = typeof document !== 'undefined' && document.body.classList.contains('war-cartoon');

const ART: Record<string, string> = {
  'arsenal.png': 'roman-legion', 'aston-villa.png': 'anglo-saxon-housecarl', 'bournemouth.png': 'barbary-corsair',
  'brentford.png': 'prussian-infantry', 'brighton.png': 'byzantine-cataphract', 'chelsea.png': 'ottoman-janissary',
  'coventry.png': 'zulu-impi', 'crystal-palace.png': 'saxon-fyrd', 'everton.png': 'norman-knight', 'fulham.png': 'venetian-marine',
  'hull.png': 'numidian-cavalry', 'ipswich.png': 'rus-varangian-guard', 'leeds.png': 'white-rose-yorkist', 'liverpool.png': 'norse-viking',
  'manchester-city.png': 'mongol-horde', 'manchester-united.png': 'napoleonic-grenadier', 'newcastle.png': 'northumbrian-raider',
  'nottingham-forest.png': 'robin-hood-outlaw', 'sunderland.png': 'northern-rebel', 'tottenham.png': 'spartan-hoplite',
  'wolves.png': 'wolf-warband',
};

export const warriorSrc = (img: string) => (CARTOON && ART[img] ? `/war/army/${ART[img]}-solid.webp` : `/war/warriors/${img}`);

// A club's mood thumbnail (cartoon only): /war/army/moods/<art>-<mood>.webp.
export const moodSrc = (img: string, mood: 'warrior' | 'victory' | 'dejected' | 'defeated') => (ART[img] ? `/war/army/moods/${ART[img]}-${mood}.webp` : '');
