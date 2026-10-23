<?php
/**
 * Wow child theme.
 *
 * @package wow-theme
 */

namespace Wow\Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Подключает style.css дочерней темы следом за родительским.
 *
 * Свой стиль родитель ставит сам, а стиль дочерней темы ядро не подключает,
 * это задача темы. Зависимость от родительского хэндла держит порядок,
 * а 'path' позволяет ядру встроить файл в страницу, пока он невелик.
 */
function enqueue_styles(): void {
	wp_enqueue_style(
		'wow-theme-style',
		get_stylesheet_uri(),
		array( 'twentytwentyfive-style' ),
		wp_get_theme()->get( 'Version' )
	);

	wp_style_add_data( 'wow-theme-style', 'path', get_stylesheet_directory() . '/style.css' );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_styles', 20 );

/**
 * Подключает скрипт мега-меню.
 *
 * Версия — от filemtime: файл не собирается, и после правки браузеру
 * нечего держать в кеше. Deferred: скрипт только слушает клики, задерживать
 * ради него страницу незачем.
 */
function enqueue_scripts(): void {
	$script = get_stylesheet_directory() . '/assets/mega-menu.js';

	wp_enqueue_script(
		'wow-theme-mega-menu',
		get_stylesheet_directory_uri() . '/assets/mega-menu.js',
		array(),
		(string) filemtime( $script ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_scripts' );

/**
 * Объявляет поддержку логотипа сайта.
 *
 * Нужна блоку «Логотип сайта» в редакторе сайта: без этой поддержки он
 * не даёт загрузить картинку. Логотип используется в подвале и первой
 * ячейкой сетки альбомов на главной.
 *
 * Кастомайзер здесь ни при чём. Раньше тема подписывалась на
 * customize_register, чтобы подписать там поле логотипа, и побочно
 * возвращала блочной теме пункт «Настроить» — см. условие в
 * wp-admin/menu.php:246 и admin-bar.php:524. Настройки давно живут
 * в редакторе сайта, поэтому подписка убрана вместе с тем пунктом.
 */
function setup(): void {
	add_theme_support(
		'custom-logo',
		array(
			'height'      => 120,
			'width'       => 480,
			'flex-height' => true,
			'flex-width'  => true,
		)
	);
}
add_action( 'after_setup_theme', __NAMESPACE__ . '\\setup' );

/**
 * Отправляет с кастомайзера в редактор сайта.
 *
 * Пункт меню и ссылку в админ-баре ядро уже не показывает, но сама страница
 * customize.php остаётся доступной по прямому адресу. Для блочной темы это
 * второй, устаревший способ править то же самое, и расхождение между двумя
 * редакторами дороже, чем удобство старого.
 *
 * Хук load-customize.php срабатывает до вывода разметки, поэтому заголовок
 * ещё можно отправить. Предпросмотр кастомайзера этим не затрагивается: он
 * открывает фронт с параметрами изменения, а не эту страницу.
 */
function redirect_customizer(): void {
	/**
	 * Позволяет вернуть кастомайзер, если он зачем-то понадобится плагину.
	 *
	 * @param bool $redirect Отправлять ли в редактор сайта.
	 */
	if ( ! apply_filters( 'wow_theme_redirect_customizer', true ) ) {
		return;
	}

	wp_safe_redirect( admin_url( 'site-editor.php' ), 302 );

	exit;
}
add_action( 'load-customize.php', __NAMESPACE__ . '\\redirect_customizer' );
