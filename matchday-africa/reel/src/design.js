// The design language's tokens for the Remotion reel: the same resources/design/tokens.json the
// PHP renderers read (App\Design\Tokens). Colours, fonts, the safe zone and the easing curve come
// from here only; see docs/design-language.md.
import { Easing } from 'remotion';
import tokens from '../../resources/design/tokens.json';

export const C = tokens.colour;
export const SAFE = tokens.grid.safe;
export const MOTION = tokens.motion;
// The standard easing curve (motion.ease), shared with the GD renderers.
export const ease = Easing.bezier(...tokens.motion.ease);

// Font files the renderer stages into public/fonts (render.mjs reads the same list).
export const FONT_FILES = Object.values(tokens.font).map((f) => f.file.split('/').pop());

// @font-face rules: Anton for headlines and scores, Inter for body (600) and labels (700).
export const fontFaces = (staticFile) => `
@font-face{font-family:Anton;src:url(${staticFile(`fonts/${tokens.font.display.file.split('/').pop()}`)})}
@font-face{font-family:Inter;src:url(${staticFile(`fonts/${tokens.font.semibold.file.split('/').pop()}`)})}
@font-face{font-family:InterBold;src:url(${staticFile(`fonts/${tokens.font.bold.file.split('/').pop()}`)})}`;

// A token colour with alpha, for gradients and shadows.
export const rgba = (hex, alpha) => {
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    return `rgba(${r},${g},${b},${alpha})`;
};

export default tokens;
