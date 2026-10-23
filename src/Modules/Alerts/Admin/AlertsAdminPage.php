<?php
/**
 * The "Alerts" admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\AdminMenu;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Fonts\AlertFont;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\Fonts\AlertFontsException;
use MerchBoard\Modules\Alerts\Images\AlertImage;
use MerchBoard\Modules\Alerts\Images\AlertImageException;
use MerchBoard\Modules\Alerts\Images\AlertImageLibrary;
use MerchBoard\Modules\Alerts\Images\ImageFiles;
use MerchBoard\Modules\Alerts\Sounds\AlertSound;
use MerchBoard\Modules\Alerts\Sounds\AlertSoundException;
use MerchBoard\Modules\Alerts\Sounds\AlertSoundLibrary;
use MerchBoard\Modules\Alerts\Sounds\SoundFiles;

defined( 'ABSPATH' ) || exit;

/**
 * "Merch Dashboard → Alerts" (ADR-065 pkt. 7), for `merchboard_manage_alerts`.
 * Phase 25 step 5 adds the tab "Fonts" (ADR-067): the Google Fonts API key,
 * the catalogue and the approved fonts. Step 6.3 adds the tab "Sounds": the
 * sound library creators can choose from; step 6.10 the tab "Images": the
 * images creators can fill their alert text with; step 8 the tab "Chatbot"
 * (ChatbotAdminTab). Step 9 adds the order log, the global overlay, the
 * standard overlays and the creator page; step 10.1 the import from
 * MerchAlert (MerchAlertImportTab). Read-only rendering; every change goes
 * through the admin-post handlers. Everything is escaped.
 */
final class AlertsAdminPage {

	public const SLUG = 'merchboard-alerts';

	/**
	 * `admin_menu` priority: after "Recalculations" (26), before "Audit log" (30).
	 */
	public const PRIORITY = 27;

	/**
	 * Catalogue rows shown per search.
	 */
	public const SEARCH_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param AlertFonts               $fonts   Font library.
	 * @param AlertSoundLibrary|null   $library Sound library; null for none.
	 * @param AlertImageLibrary|null   $images  Image library; null for none.
	 * @param ChatbotAdminTab|null     $chatbot The chatbot tab; null for none.
	 * @param YouTubeLegalTab|null     $legal   The YouTube chatbot's privacy policy and terms; null for none.
	 * @param OrderLogTab|null         $log     The order log (step 25.9.3), the first and default tab; null for none.
	 * @param GlobalOverlayTab|null    $global_overlay The global overlay (step 25.9.4); null for none.
	 * @param OverlayPresetsTab|null   $presets The standard overlays (step 25.9.5); null for none.
	 * @param CreatorPageTab|null      $creator_page The setup of the creator's page (step 25.9.6); null for none.
	 * @param MerchAlertImportTab|null $import The import from MerchAlert (step 25.10.1); null for none.
	 */
	public function __construct( private AlertFonts $fonts, private ?AlertSoundLibrary $library = null, private ?AlertImageLibrary $images = null, private ?ChatbotAdminTab $chatbot = null, private ?YouTubeLegalTab $legal = null, private ?OrderLogTab $log = null, private ?GlobalOverlayTab $global_overlay = null, private ?OverlayPresetsTab $presets = null, private ?CreatorPageTab $creator_page = null, private ?MerchAlertImportTab $import = null ) {
	}

	/**
	 * Adds the submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			AdminMenu::PARENT_SLUG,
			__( 'Merch Dashboard Alerts', 'merchboard' ),
			__( 'Alerts', 'merchboard' ),
			Capabilities::MANAGE_ALERTS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * URL of a tab with an outcome code.
	 *
	 * @param string $tab    Tab.
	 * @param string $result Outcome code ('' for none).
	 */
	public static function url( string $tab = 'fonts', string $result = '' ): string {
		$args = array(
			'page' => self::SLUG,
			'tab'  => $tab,
		);

		if ( '' !== $result ) {
			$args[ AlertsAdminActions::RESULT_ARG ] = $result;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'key_saved'                              => array( __( 'The Google Fonts API key was saved.', 'merchboard' ), 'success' ),
			'key_removed'                            => array( __( 'The Google Fonts API key was removed.', 'merchboard' ), 'success' ),
			'synced'                                 => array( __( 'The Google Fonts catalogue was fetched.', 'merchboard' ), 'success' ),
			'approved'                               => array( __( 'The font was approved. Creators can choose it now.', 'merchboard' ), 'success' ),
			'removed'                                => array( __( 'The font was removed. Overlays that used it show the default font.', 'merchboard' ), 'success' ),
			'no_changes'                             => array( __( 'Nothing was changed.', 'merchboard' ), 'info' ),
			AlertFontsException::NOT_AUTHORIZED      => array( __( 'You are not allowed to change these settings.', 'merchboard' ), 'error' ),
			AlertFontsException::INVALID_KEY         => array( __( 'Google did not accept the API key. Check the key and try again.', 'merchboard' ), 'error' ),
			AlertFontsException::NO_KEY              => array( __( 'Save a Google Fonts API key first.', 'merchboard' ), 'error' ),
			AlertFontsException::UNAVAILABLE         => array( __( 'Google Fonts could not be reached. Try again later.', 'merchboard' ), 'error' ),
			AlertFontsException::UNKNOWN_FONT        => array( __( 'The font is not in the catalogue, or the limit of approved fonts is reached.', 'merchboard' ), 'error' ),
			'sound_added'                            => array( __( 'The sound was added to the library. Creators can choose it now.', 'merchboard' ), 'success' ),
			'sound_renamed'                          => array( __( 'The sound got its new name.', 'merchboard' ), 'success' ),
			AlertSoundException::UNKNOWN_SOUND       => array( __( 'The sound is no longer in the library.', 'merchboard' ), 'error' ),
			'sound_removed'                          => array( __( 'The sound was removed. Overlays that used it play their other sounds.', 'merchboard' ), 'success' ),
			AlertSoundException::NO_FILE             => array( __( 'No file was received. Try again.', 'merchboard' ), 'error' ),
			AlertSoundException::INVALID_TYPE        => array( __( 'Choose an MP3, WAV or OGG file.', 'merchboard' ), 'error' ),
			/* translators: %s: the largest file, e.g. "10 MB". */
			AlertSoundException::TOO_LARGE           => array( sprintf( __( 'The file is larger than %s.', 'merchboard' ), size_format( SoundFiles::max_bytes() ) ), 'error' ),
			/* translators: %d: seconds. */
			AlertSoundException::TOO_LONG            => array( sprintf( __( 'The sound is longer than %d seconds.', 'merchboard' ), SoundFiles::max_seconds() ), 'error' ),
			AlertSoundException::UNREADABLE          => array( __( 'The file could not be read as a sound. Choose another file.', 'merchboard' ), 'error' ),
			AlertSoundException::LIBRARY_FULL        => array( __( 'The library is full. Remove a sound first.', 'merchboard' ), 'error' ),
			'image_added'                            => array( __( 'The image was added to the library. Creators can choose it now.', 'merchboard' ), 'success' ),
			'image_renamed'                          => array( __( 'The image got its new name.', 'merchboard' ), 'success' ),
			'image_removed'                          => array( __( 'The image was removed. Texts it filled show their colour again.', 'merchboard' ), 'success' ),
			'image_' . AlertImageException::UNKNOWN_IMAGE => array( __( 'The image is no longer in the library.', 'merchboard' ), 'error' ),
			'image_' . AlertImageException::NO_FILE  => array( __( 'No file was received. Try again.', 'merchboard' ), 'error' ),
			'image_' . AlertImageException::INVALID_TYPE => array( __( 'Choose a PNG, JPG, GIF or WebP image.', 'merchboard' ), 'error' ),
			'image_' . AlertImageException::TOO_LARGE => array( __( 'The file is too large.', 'merchboard' ), 'error' ),
			'image_' . AlertImageException::TOO_BIG  => array( __( 'The image is too big. Choose a smaller image.', 'merchboard' ), 'error' ),
			'image_' . AlertImageException::UNREADABLE => array( __( 'The file could not be read as an image. Choose another file.', 'merchboard' ), 'error' ),
			'image_' . AlertImageException::LIBRARY_FULL => array( __( 'The library is full. Remove an image first.', 'merchboard' ), 'error' ),
			'image_' . AlertImageException::NOT_AUTHORIZED => array( __( 'You are not allowed to change these settings.', 'merchboard' ), 'error' ),
			'error'                                  => array( __( 'A technical error occurred. Nothing was changed.', 'merchboard' ), 'error' ),
			default                                  => ChatbotAdminTab::result_message( $code ) ?? MerchAlertImportTab::result_message( $code ),
		};
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage alerts.', 'merchboard' ), '', array( 'response' => 403 ) );
		}

		// Read-only parameters; nothing is changed by this request.
		$code   = isset( $_GET[ AlertsAdminActions::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ AlertsAdminActions::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A search of the view.
		$tab    = match ( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
			'sounds' => null === $this->library ? 'fonts' : 'sounds',
			'images' => null === $this->images ? 'fonts' : 'images',
			'chatbot' => null === $this->chatbot ? 'fonts' : 'chatbot',
			'youtube-legal' => null === $this->legal ? 'fonts' : 'youtube-legal',
			'fonts'  => 'fonts',
			GlobalOverlayTab::TAB => null === $this->global_overlay ? 'fonts' : GlobalOverlayTab::TAB,
			OverlayPresetsTab::TAB => null === $this->presets ? 'fonts' : OverlayPresetsTab::TAB,
			CreatorPageTab::TAB => null === $this->creator_page ? 'fonts' : CreatorPageTab::TAB,
			MerchAlertImportTab::TAB => null === $this->import ? 'fonts' : MerchAlertImportTab::TAB,
			default  => null === $this->log ? 'fonts' : OrderLogTab::TAB,
		};
		$notice = self::result_message( $code );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Alerts', 'merchboard' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';

		if ( null !== $this->log ) {
			echo '<a class="nav-tab' . ( OrderLogTab::TAB === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( OrderLogTab::TAB ) ) . '"' . ( OrderLogTab::TAB === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'Order log', 'merchboard' ) . '</a>';
		}

		if ( null !== $this->global_overlay ) {
			echo '<a class="nav-tab' . ( GlobalOverlayTab::TAB === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( GlobalOverlayTab::TAB ) ) . '"' . ( GlobalOverlayTab::TAB === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'Global overlay', 'merchboard' ) . '</a>';
		}

		if ( null !== $this->presets ) {
			echo '<a class="nav-tab' . ( OverlayPresetsTab::TAB === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( OverlayPresetsTab::TAB ) ) . '"' . ( OverlayPresetsTab::TAB === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'Standard overlays', 'merchboard' ) . '</a>';
		}

		if ( null !== $this->creator_page ) {
			echo '<a class="nav-tab' . ( CreatorPageTab::TAB === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( CreatorPageTab::TAB ) ) . '"' . ( CreatorPageTab::TAB === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'Creator page', 'merchboard' ) . '</a>';
		}

		if ( null !== $this->import ) {
			echo '<a class="nav-tab' . ( MerchAlertImportTab::TAB === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( MerchAlertImportTab::TAB ) ) . '"' . ( MerchAlertImportTab::TAB === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'Import', 'merchboard' ) . '</a>';
		}

		echo '<a class="nav-tab' . ( 'fonts' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url() ) . '"' . ( 'fonts' === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'Fonts', 'merchboard' ) . '</a>';

		if ( null !== $this->library ) {
			echo '<a class="nav-tab' . ( 'sounds' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( 'sounds' ) ) . '"' . ( 'sounds' === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'Sounds', 'merchboard' ) . '</a>';
		}

		if ( null !== $this->chatbot ) {
			echo '<a class="nav-tab' . ( 'chatbot' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( 'chatbot' ) ) . '"' . ( 'chatbot' === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'Chatbot', 'merchboard' ) . '</a>';
		}

		if ( null !== $this->images ) {
			echo '<a class="nav-tab' . ( 'images' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( 'images' ) ) . '"' . ( 'images' === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'Images', 'merchboard' ) . '</a>';
		}

		if ( null !== $this->legal ) {
			echo '<a class="nav-tab' . ( 'youtube-legal' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( 'youtube-legal' ) ) . '"' . ( 'youtube-legal' === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html__( 'YouTube terms', 'merchboard' ) . '</a>';
		}

		echo '</nav>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		if ( MerchAlertImportTab::TAB === $tab && null !== $this->import ) {
			// Read-only: shows the dry run, changes nothing.
			$this->import->render( isset( $_GET[ MerchAlertImportTab::PREVIEW_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
			echo '</div>';

			return;
		}

		if ( CreatorPageTab::TAB === $tab && null !== $this->creator_page ) {
			$this->creator_page->render();
			echo '</div>';

			return;
		}

		if ( OverlayPresetsTab::TAB === $tab && null !== $this->presets ) {
			$this->presets->render();
			echo '</div>';

			return;
		}

		if ( GlobalOverlayTab::TAB === $tab && null !== $this->global_overlay ) {
			$this->global_overlay->render();
			echo '</div>';

			return;
		}

		if ( OrderLogTab::TAB === $tab && null !== $this->log ) {
			try {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: the period is matched against the known ones and the page is a number.
				$this->log->render( isset( $_GET[ OrderLogTab::PERIOD_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ OrderLogTab::PERIOD_ARG ] ) ) : '', isset( $_GET[ OrderLogTab::PAGE_ARG ] ) ? absint( $_GET[ OrderLogTab::PAGE_ARG ] ) : 1 );
			} catch ( DatabaseException ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'The order log could not be read. Please try again later.', 'merchboard' ) . '</p></div>';
			}
			echo '</div>';

			return;
		}

		$library = 'sounds' === $tab ? $this->library : null;

		if ( null !== $library ) {
			$this->render_sounds( $library );
			echo '</div>';

			return;
		}

		if ( 'chatbot' === $tab && null !== $this->chatbot ) {
			try {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Display only: which channel's chat is shown; the platform is matched against the known ones.
				$this->chatbot->render( isset( $_GET['chat'] ) ? absint( $_GET['chat'] ) : 0, ChatPlatform::parse( isset( $_GET['platform'] ) ? sanitize_key( wp_unslash( $_GET['platform'] ) ) : '' ) ?? ChatPlatform::Twitch );
			} catch ( DatabaseException ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'A technical error occurred. Nothing was changed.', 'merchboard' ) . '</p></div>';
			}
			echo '</div>';

			return;
		}

		if ( 'youtube-legal' === $tab && null !== $this->legal ) {
			$this->legal->render();
			echo '</div>';

			return;
		}

		if ( 'images' === $tab && null !== $this->images ) {
			$this->render_images( $this->images );
			echo '</div>';

			return;
		}

		echo '<p>' . esc_html__( 'Creators can use the approved fonts in their alert overlay. The fonts are loaded from Google\'s servers in the creator dashboard and in the browser source, which sends the viewer\'s IP address to Google (ADR-067).', 'merchboard' ) . '</p>';

		$this->render_key();
		$this->render_approved();
		$this->render_catalog( $search );
		echo '</div>';
	}

	/**
	 * The sound library: upload and the list of sounds.
	 *
	 * @param AlertSoundLibrary $library Sound library.
	 */
	private function render_sounds( AlertSoundLibrary $library ): void {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: size, e.g. "10 MB", 2: seconds, 3: number of sounds. */
				__( 'Creators can choose these sounds for their alerts instead of uploading their own. MP3, WAV or OGG, at most %1$s and %2$d seconds each (Settings → Alerts); at most %3$d sounds.', 'merchboard' ),
				size_format( SoundFiles::max_bytes() ),
				SoundFiles::max_seconds(),
				AlertSoundLibrary::MAX_SOUNDS
			)
		) . '</p>';

		echo '<h2>' . esc_html__( 'Add a sound', 'merchboard' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertsAdminActions::SOUND_ADD_ACTION ) . '">';
		wp_nonce_field( AlertsAdminActions::SOUND_ADD_ACTION );
		echo '<p><label for="merchboard-alert-sound-file">' . esc_html__( 'Sound file', 'merchboard' ) . '</label><br>';
		echo '<input type="file" id="merchboard-alert-sound-file" name="file" accept=".mp3,.wav,.ogg,.oga,audio/mpeg,audio/wav,audio/ogg" required></p>';
		echo '<p><label for="merchboard-alert-sound-name">' . esc_html__( 'Name (optional)', 'merchboard' ) . '</label><br>';
		echo '<input type="text" id="merchboard-alert-sound-name" name="name" class="regular-text" maxlength="' . esc_attr( (string) AlertSoundLibrary::NAME_MAX ) . '" aria-describedby="merchboard-alert-sound-name-help">';
		echo '<span id="merchboard-alert-sound-name-help" class="description"> ' . esc_html__( 'Creators see this name. Without a name, the file name is used.', 'merchboard' ) . '</span></p>';
		submit_button( __( 'Upload sound', 'merchboard' ), 'secondary', 'submit', false );
		echo '</form>';

		$sounds = $library->all();

		echo '<h2>' . esc_html__( 'Sound library', 'merchboard' ) . '</h2>';

		if ( array() === $sounds ) {
			echo '<p>' . esc_html__( 'The library is empty. Creators can only use their own sounds.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'merchboard' ) . '</th><th>' . esc_html__( 'Length', 'merchboard' ) . '</th><th>' . esc_html__( 'Play', 'merchboard' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'merchboard' ) . '</span></th></tr></thead><tbody>';

		foreach ( $sounds as $sound ) {
			echo '<tr><td>';
			$this->rename_form( $sound );
			echo '</td><td>' . esc_html(
				sprintf(
					/* translators: %d: seconds. */
					_n( '%d second', '%d seconds', $sound->seconds, 'merchboard' ),
					$sound->seconds
				)
			) . '</td><td><audio controls preload="none" src="' . esc_url( $sound->url ) . '"></audio></td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( AlertsAdminActions::SOUND_REMOVE_ACTION ) . '">';
			echo '<input type="hidden" name="sound" value="' . esc_attr( (string) $sound->attachment_id ) . '">';
			wp_nonce_field( AlertsAdminActions::nonce_action( AlertsAdminActions::SOUND_REMOVE_ACTION, (string) $sound->attachment_id ) );
			submit_button( __( 'Remove', 'merchboard' ), 'small', 'submit', false );
			echo '</form></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The image library: upload and the list of images.
	 *
	 * @param AlertImageLibrary $library Image library.
	 */
	private function render_images( AlertImageLibrary $library ): void {
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: size in MB, 2: pixels, 3: number of images. */
				__( 'Creators can fill their alert text with these images instead of uploading their own. PNG, JPG, GIF or WebP, at most %1$s MB and %2$d pixels wide and high each; at most %3$d images. Animated GIFs keep moving.', 'merchboard' ),
				number_format_i18n( ImageFiles::max_bytes() / 1048576, 1 ),
				ImageFiles::MAX_PIXELS,
				AlertImageLibrary::MAX_IMAGES
			)
		) . '</p>';

		echo '<h2>' . esc_html__( 'Add an image', 'merchboard' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertsAdminActions::IMAGE_ADD_ACTION ) . '">';
		wp_nonce_field( AlertsAdminActions::IMAGE_ADD_ACTION );
		echo '<p><label for="merchboard-alert-image-file">' . esc_html__( 'Image file', 'merchboard' ) . '</label><br>';
		echo '<input type="file" id="merchboard-alert-image-file" name="file" accept=".png,.jpg,.jpeg,.gif,.webp,image/png,image/jpeg,image/gif,image/webp" required></p>';
		echo '<p><label for="merchboard-alert-image-name">' . esc_html__( 'Name (optional)', 'merchboard' ) . '</label><br>';
		echo '<input type="text" id="merchboard-alert-image-name" name="name" class="regular-text" maxlength="' . esc_attr( (string) AlertImageLibrary::NAME_MAX ) . '" aria-describedby="merchboard-alert-image-name-help">';
		echo '<span id="merchboard-alert-image-name-help" class="description"> ' . esc_html__( 'Creators see this name. Without a name, the file name is used.', 'merchboard' ) . '</span></p>';
		submit_button( __( 'Upload image', 'merchboard' ), 'secondary', 'submit', false );
		echo '</form>';

		$images = $library->all();

		echo '<h2>' . esc_html__( 'Image library', 'merchboard' ) . '</h2>';

		if ( array() === $images ) {
			echo '<p>' . esc_html__( 'The library is empty. Creators can only use their own images and the ready-made fills.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Image', 'merchboard' ) . '</th><th>' . esc_html__( 'Name', 'merchboard' ) . '</th><th>' . esc_html__( 'Size', 'merchboard' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'merchboard' ) . '</span></th></tr></thead><tbody>';

		foreach ( $images as $image ) {
			echo '<tr><td><img src="' . esc_url( $image->url ) . '" alt="" width="96" height="54" style="object-fit:cover"></td><td>';
			$this->rename_image_form( $image );
			echo '</td><td>' . esc_html( $image->width . ' × ' . $image->height ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( AlertsAdminActions::IMAGE_REMOVE_ACTION ) . '">';
			echo '<input type="hidden" name="image" value="' . esc_attr( (string) $image->attachment_id ) . '">';
			wp_nonce_field( AlertsAdminActions::nonce_action( AlertsAdminActions::IMAGE_REMOVE_ACTION, (string) $image->attachment_id ) );
			submit_button( __( 'Remove', 'merchboard' ), 'small', 'submit', false );
			echo '</form></td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The name of a library image with a form to change it.
	 *
	 * @param AlertImage $image Library image.
	 */
	private function rename_image_form( AlertImage $image ): void {
		$field = 'merchboard-alert-image-name-' . $image->attachment_id;

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertsAdminActions::IMAGE_RENAME_ACTION ) . '">';
		echo '<input type="hidden" name="image" value="' . esc_attr( (string) $image->attachment_id ) . '">';
		wp_nonce_field( AlertsAdminActions::nonce_action( AlertsAdminActions::IMAGE_RENAME_ACTION, (string) $image->attachment_id ) );
		echo '<label for="' . esc_attr( $field ) . '" class="screen-reader-text">' . esc_html__( 'Name', 'merchboard' ) . '</label>';
		echo '<input type="text" id="' . esc_attr( $field ) . '" name="name" value="' . esc_attr( $image->name ) . '" maxlength="' . esc_attr( (string) AlertImageLibrary::NAME_MAX ) . '"> ';
		submit_button( __( 'Rename', 'merchboard' ), 'small', 'submit', false );
		echo '</form>';
	}

	/**
	 * The name of a library sound with a form to change it.
	 *
	 * @param AlertSound $sound Library sound.
	 */
	private function rename_form( AlertSound $sound ): void {
		$field = 'merchboard-alert-sound-name-' . $sound->attachment_id;

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertsAdminActions::SOUND_RENAME_ACTION ) . '">';
		echo '<input type="hidden" name="sound" value="' . esc_attr( (string) $sound->attachment_id ) . '">';
		wp_nonce_field( AlertsAdminActions::nonce_action( AlertsAdminActions::SOUND_RENAME_ACTION, (string) $sound->attachment_id ) );
		echo '<label for="' . esc_attr( $field ) . '" class="screen-reader-text">' . esc_html__( 'Name', 'merchboard' ) . '</label>';
		echo '<input type="text" id="' . esc_attr( $field ) . '" name="name" value="' . esc_attr( $sound->name ) . '" maxlength="' . esc_attr( (string) AlertSoundLibrary::NAME_MAX ) . '"> ';
		submit_button( __( 'Rename', 'merchboard' ), 'small', 'submit', false );
		echo '</form>';
	}

	/**
	 * The API key form.
	 */
	private function render_key(): void {
		echo '<h2>' . esc_html__( 'Google Fonts API key', 'merchboard' ) . '</h2>';
		echo '<p>' . esc_html( $this->fonts->has_key() ? __( 'A key is saved. It is never shown again.', 'merchboard' ) : __( 'No key is saved. The key is only needed to fetch the catalogue.', 'merchboard' ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertsAdminActions::KEY_ACTION ) . '">';
		wp_nonce_field( AlertsAdminActions::KEY_ACTION );
		echo '<label for="merchboard-alert-fonts-key" class="screen-reader-text">' . esc_html__( 'API key', 'merchboard' ) . '</label>';
		echo '<input type="password" id="merchboard-alert-fonts-key" name="key" class="regular-text" autocomplete="off" spellcheck="false"> ';
		submit_button( __( 'Save key', 'merchboard' ), 'secondary', 'submit', false );

		if ( $this->fonts->has_key() ) {
			echo ' <button type="submit" name="remove" value="1" class="button-link button-link-delete">' . esc_html__( 'Remove key', 'merchboard' ) . '</button>';
		}

		echo '</form>';
	}

	/**
	 * The approved fonts.
	 */
	private function render_approved(): void {
		$approved = $this->fonts->approved();

		echo '<h2>' . esc_html__( 'Approved fonts', 'merchboard' ) . '</h2>';

		if ( array() === $approved ) {
			echo '<p>' . esc_html__( 'No fonts are approved. Creators can only use the default font.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Font', 'merchboard' ) . '</th><th>' . esc_html__( 'Category', 'merchboard' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'merchboard' ) . '</span></th></tr></thead><tbody>';

		foreach ( $approved as $font ) {
			echo '<tr><td>' . esc_html( $font->family ) . '</td><td>' . esc_html( $font->category ) . '</td><td>';
			$this->font_button( AlertsAdminActions::REMOVE_ACTION, $font, __( 'Remove', 'merchboard' ) );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The catalogue with search.
	 *
	 * @param string $search Search text.
	 */
	private function render_catalog( string $search ): void {
		echo '<h2>' . esc_html__( 'Google Fonts catalogue', 'merchboard' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( AlertsAdminActions::SYNC_ACTION ) . '">';
		wp_nonce_field( AlertsAdminActions::SYNC_ACTION );
		submit_button( $this->fonts->has_catalog() ? __( 'Fetch the catalogue again', 'merchboard' ) : __( 'Fetch the catalogue', 'merchboard' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( ! $this->fonts->has_catalog() ) {
			return;
		}

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="search-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="fonts">';
		echo '<p><label for="merchboard-alert-fonts-search">' . esc_html__( 'Search fonts', 'merchboard' ) . '</label> ';
		echo '<input type="search" id="merchboard-alert-fonts-search" name="q" value="' . esc_attr( $search ) . '"> ';
		submit_button( __( 'Search', 'merchboard' ), 'secondary', '', false );
		echo '</p></form>';

		$approved = $this->fonts->approved();
		$fonts    = $this->fonts->catalog( $search, self::SEARCH_LIMIT );

		if ( array() === $fonts ) {
			echo '<p>' . esc_html__( 'No fonts match the search.', 'merchboard' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %d: number of fonts. */
				__( 'At most %d fonts, the most popular first.', 'merchboard' ),
				self::SEARCH_LIMIT
			)
		) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Font', 'merchboard' ) . '</th><th>' . esc_html__( 'Category', 'merchboard' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'merchboard' ) . '</span></th></tr></thead><tbody>';

		foreach ( $fonts as $font ) {
			echo '<tr><td>' . esc_html( $font->family ) . '</td><td>' . esc_html( $font->category ) . '</td><td>';

			if ( isset( $approved[ $font->family ] ) ) {
				echo esc_html__( 'Approved', 'merchboard' );
			} else {
				$this->font_button( AlertsAdminActions::APPROVE_ACTION, $font, __( 'Approve', 'merchboard' ) );
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * A one-button form for a font.
	 *
	 * @param string    $action Admin-post action.
	 * @param AlertFont $font   Font.
	 * @param string    $label  Button label.
	 */
	private function font_button( string $action, AlertFont $font, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="family" value="' . esc_attr( $font->family ) . '">';
		wp_nonce_field( AlertsAdminActions::nonce_action( $action, $font->family ) );
		submit_button( $label, 'small', 'submit', false );
		echo '</form>';
	}
}
