import React from 'react';
import { Composition, registerRoot } from 'remotion';
import { Reel } from './Reel.jsx';
import { FPS, TOTAL } from './timeline.js';
import sample from './sample.json';

const SIZES = { story: [1080, 1920], square: [1080, 1080] };

const Root = () => (
    <>
        <Composition
            id="Reel"
            component={Reel}
            durationInFrames={TOTAL}
            fps={FPS}
            width={1080}
            height={1920}
            defaultProps={sample}
            calculateMetadata={({ props }) => {
                const [width, height] = SIZES[props.format] ?? SIZES.story;
                return { width, height };
            }}
        />
        {/* Two-second clip used by check.mjs to prove the server can render. */}
        <Composition id="Check" component={Reel} durationInFrames={2 * FPS} fps={FPS} width={540} height={960} defaultProps={sample} />
    </>
);

registerRoot(Root);
