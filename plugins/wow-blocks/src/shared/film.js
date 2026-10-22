/**
 * A film in a cell, on the editor's canvas.
 *
 * The same two copies and the same crossing as the page uses, so that the
 * loop being made is the loop that will be seen. Everything here is on
 * screen already, so there is none of the page's patience about fetching:
 * both copies are given the film at once and it plays.
 */

import { useEffect, useRef } from '@wordpress/element';

import softLoop from './soft-loop';

/**
 * @param {Object} props
 * @param {string} props.url    The film.
 * @param {string} props.poster Its first frame, shown until it plays.
 * @return {Element} The markup.
 */
export default function Film( { url, poster } ) {
	const first = useRef( null );
	const second = useRef( null );

	useEffect( () => {
		if ( ! first.current || ! second.current ) {
			return undefined;
		}

		const reel = softLoop( [ first.current, second.current ] );

		reel.play();

		return reel.stop;
	}, [ url ] );

	return (
		<span className="wow-cell__film">
			{  }
			<video
				ref={ first }
				className="wow-cell__video"
				src={ url }
				poster={ poster || undefined }
				muted
				playsInline
			/>
			<video
				ref={ second }
				className="wow-cell__video"
				src={ url }
				muted
				playsInline
			/>
			{  }
		</span>
	);
}
