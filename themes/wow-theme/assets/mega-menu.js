/**
 * Закрывает мега-меню кликом мимо него.
 *
 * Ядро закрывает подменю по событию focusout, когда фокус уходит за его
 * пределы (relatedTarget === null или вне подменю). Клик по нефокусируемому
 * месту страницы должен вызывать именно это — но не во всех браузерах фокус
 * при таком клике действительно покидает кнопку. Здесь это доводится до
 * конца двумя способами, каждый из которых сам по себе безвреден:
 *
 * 1. если фокус остался внутри подменю — снимаем его, и ядро получит
 *    настоящий focusout;
 * 2. в любом случае отправляем кнопке синтетический focusout без
 *    relatedTarget — тот самый сигнал, на который ядро закрывает меню.
 *
 * Хранилище core/navigation заперто (lock: true), так что дёрнуть его
 * closeMenu() напрямую нельзя. А имитировать клик по кнопке нельзя по
 * другой причине: toggleMenuOnClick() в ядре вызывает ref.focus(), и
 * страницу прокрутило бы к шапке.
 *
 * В мобильной шторке (.is-menu-open) подменю раскрыто всегда, и ей это
 * не нужно — там скрипт молчит.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var items = document.querySelectorAll( '.wow-mega.has-child' );

		Array.prototype.forEach.call( items, function ( item ) {
			var toggle = item.querySelector( '.wp-block-navigation-submenu__toggle' );

			if ( ! toggle || 'true' !== toggle.getAttribute( 'aria-expanded' ) ) {
				return;
			}

			if ( item.contains( event.target ) || item.closest( '.is-menu-open' ) ) {
				return;
			}

			var active = document.activeElement;

			if ( active && item.contains( active ) ) {
				active.blur();
			}

			toggle.dispatchEvent( new FocusEvent( 'focusout', { bubbles: true, relatedTarget: null } ) );
		} );
	} );
}() );
