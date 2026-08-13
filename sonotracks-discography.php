<?php
/**
 * Plugin Name: sonoTracks Discography
 * Plugin URI:  https://sono-tracks.com/
 * Description: sonoTracks で販売している自分の作品一覧を、ショートコード [sonotracks_discography] でサイトに表示します。作品を追加・公開・非公開にすると自動で反映されます。
 * Version:     1.3.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      sono
 * Author URI:  https://sono-tracks.com/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:  https://sono-tracks.com/
 *
 * ★ Update URI を必ず残すこと。これが無いと、将来 wordpress.org に
 *   同名（sonotracks-discography）のプラグインを誰かが公開したとき、
 *   WordPress の更新チェックがそれを「このプラグインの更新」として案内し、
 *   アーティストのワンクリックで**別人のコードに上書きされる**。
 *   自前配布のプラグインで実際に起きている乗っ取りの経路。
 *
 * ──────────────────────────────────────────────────────────────────────────
 * これは **アーティストご自身の WordPress サイト** に入れるプラグインです
 * （音楽メディア media.sono-music.com 側は、テーマの
 *  inc/setup-sonotracks-new-releases.php が同じAPIを別の鍵で読んでいます）。
 *
 * 【設計の要】docs/sonotracks-spec.md 第9.4章の pull 型。
 *   WordPress が sonoTracks の公開APIを**読みに行く**。sonoTracks から
 *   WordPress へ投稿を書きに行く（push）ことはしない。作品の状態（価格・公開
 *   状況）は sonoTracks 側でしか変わらないので、正を1つにしておけば
 *   「公開したのに出ない／消したのに残る」が原理的に起きなくなる。
 *
 * ★ 取得は必ずサーバーサイド（wp_remote_get）で行い、transient に10分だけ
 *   キャッシュする。ブラウザから直接APIを叩かせない（第9.4章の方針）。
 *
 * ★ APIが落ちている・遅いときは、期限切れでも「最後に成功した表示」を出す。
 *   それも無ければ**何も出さない**。エラー文言を人様のサイトの本文に出さない。
 *
 * ★ 関数を裸で置かず final class の静的メソッドにまとめてある。配布先のサイトに
 *   別のプラグインやテーマが入っている前提なので、sonotracks_ で始まる裸の関数を
 *   増やすと衝突しうる（メディアのテーマ側は同名の関数群を持っている）。
 * ──────────────────────────────────────────────────────────────────────────
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'SonoTracks_Discography' ) ) :

final class SonoTracks_Discography {

	const VERSION = '1.3.1';

	/** 設定を1つの option にまとめる（増えたときに option が散らからないように） */
	const OPTION = 'sonotracks_discography_settings';

	/** WordPress のプラグイン一覧での識別子。zip を展開したフォルダ名と同じ */
	const PLUGIN_SLUG = 'sonotracks-discography';

	/** 取得結果の transient / last-good option の接頭辞 */
	const CACHE_PREFIX = 'sonotracks_dg_';

	/**
	 * キャッシュの世代。「表示を今すぐ更新する」で1つ進める。
	 *
	 * ★ 以前は件数（1〜24）を総当たりで delete していたが、ページ送りが入って
	 *   鍵が（件数 × ページ）になり、総当たりでは追えなくなった。世代を鍵に混ぜ、
	 *   1つ進めるだけで**全部を無効にする**形に変える。
	 * ★ 世代を混ぜるのは transient だけ。障害時の保険（last-good）まで
	 *   無効にすると、「更新」を押した直後に sonoTracks が落ちていた場合に
	 *   何も出せなくなる。保険は世代に関係なく残す。
	 */
	const VERSION_OPTION = 'sonotracks_discography_cache_version';

	/** ページ送りのクエリ名（お客様のサイトのURLに付く） */
	const PAGE_QUERY = 'sonotracks_page';

	/**
	 * 受け付けるページ番号の上限。
	 * 1ページ最大24件なので、これでも24,000件ぶん。実在の作品数より十分大きく、
	 * かつ**訪問者が投げてくる桁の大きい番号**を持ち回らないための柵。
	 */
	const MAX_PAGE = 1000;

	/** 第9.4章の決定どおり10分。短いほど反映が速く、長いほどサイトが軽い */
	const CACHE_MINUTES = 10;

	/** 既定・最大の表示件数。API側の丸めと合わせてある */
	const DEFAULT_LIMIT = 12;
	const MAX_LIMIT     = 24;

	/**
	 * ID（slug）の形。
	 *
	 * ★ **sonoTracks で slug を作る側と同じ形にすること**（登録・改名の値域）。
	 *   ここを独自に厳しくすると、実際に作れてしまう形の ID を持つ方だけが、
	 *   プロフィールページは正常なのにこのプラグインでは弾かれる、という
	 *   本人には理由の分からない不具合になる。
	 */
	const SLUG_PATTERN = '/^[a-z0-9-]{2,40}$/';

	public static function init() {
		add_shortcode( 'sonotracks_discography', array( __CLASS__, 'shortcode' ) );
		// ★ ヘッダーの Update URI のホスト名でフィルタ名が決まる（WordPress 5.8以降）。
		//   ここを実装しておくと、管理画面の「更新があります」に載る。
		add_filter( 'update_plugins_sono-tracks.com', array( __CLASS__, 'check_update' ), 10, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_sonotracks_dg_flush', array( __CLASS__, 'handle_flush' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( __FILE__ ),
			array( __CLASS__, 'plugin_action_links' )
		);
	}

	// ── 設定 ────────────────────────────────────────────────────────────

	/**
	 * 保存済みの sonoTracks の ID（slug）。未設定なら空文字。
	 */
	public static function get_slug() {
		$settings = get_option( self::OPTION, array() );
		return ( is_array( $settings ) && isset( $settings['slug'] ) ) ? (string) $settings['slug'] : '';
	}

	/**
	 * 設定画面から変えられる見た目の項目。
	 *
	 * ★ 形（type）ごとに、入れてよい値を**許すものだけ**決めてある。
	 *   ここを通らない値は捨てて、CSS 側の既定にそのまま任せる。
	 *   入れた文字列は `<style>` の中に出るので、`}` や `</style>` の
	 *   混入を「除く」のではなく「そもそも通さない」方針にしている。
	 * ★ 変数名は assets/sonotracks-discography.css の var() と同じもの。
	 *   ずれると設定しても効かないので、README の表とも揃えること。
	 */
	private static function style_fields() {
		return array(
			'gap'            => array( 'label' => '作品どうしの間隔', 'var' => '--sonotracks-dg-gap', 'type' => 'length', 'placeholder' => '16px' ),
			'min'            => array( 'label' => '1枠の最小幅', 'var' => '--sonotracks-dg-min', 'type' => 'length', 'placeholder' => '140px' ),
			'radius'         => array( 'label' => 'ジャケットの角丸', 'var' => '--sonotracks-dg-radius', 'type' => 'length', 'placeholder' => '4px' ),
			'ratio'          => array( 'label' => 'ジャケットの縦横比', 'var' => '--sonotracks-dg-ratio', 'type' => 'ratio', 'placeholder' => '1 / 1' ),
			'link_color'     => array( 'label' => '作品カードの文字色', 'var' => '--sonotracks-dg-link-color', 'type' => 'color' ),
			'title_color'    => array( 'label' => '作品名の色', 'var' => '--sonotracks-dg-title-color', 'type' => 'color' ),
			'title_weight'   => array( 'label' => '作品名の太さ', 'var' => '--sonotracks-dg-title-weight', 'type' => 'weight' ),
			'title_size'     => array( 'label' => '作品名の大きさ', 'var' => '--sonotracks-dg-title-size', 'type' => 'length', 'placeholder' => '1em' ),
			'artist_color'   => array( 'label' => 'アーティスト名の色', 'var' => '--sonotracks-dg-artist-color', 'type' => 'color' ),
			'artist_opacity' => array( 'label' => 'アーティスト名の薄さ', 'var' => '--sonotracks-dg-artist-opacity', 'type' => 'opacity', 'placeholder' => '0.8' ),
			'price_color'    => array( 'label' => '価格の色', 'var' => '--sonotracks-dg-price-color', 'type' => 'color' ),
			'meta_size'      => array( 'label' => 'アーティスト名と価格の大きさ', 'var' => '--sonotracks-dg-meta-size', 'type' => 'length', 'placeholder' => '0.9em' ),
			'pager_color'    => array( 'label' => 'ページ送りの文字色', 'var' => '--sonotracks-dg-pager-color', 'type' => 'color' ),
			'pager_current'  => array( 'label' => '現在ページの下線の色', 'var' => '--sonotracks-dg-pager-current', 'type' => 'color' ),
		);
	}

	/** 保存済みの見た目の設定 */
	public static function get_style() {
		$settings = get_option( self::OPTION, array() );
		return ( is_array( $settings ) && isset( $settings['style'] ) && is_array( $settings['style'] ) )
			? $settings['style'] : array();
	}

	/**
	 * 見た目の値を1つ検証する。**通らなければ空文字**（＝設定なし・既定のまま）。
	 *
	 * @return string
	 */
	private static function sanitize_style_value( $type, $raw ) {
		// 文字列以外（配列など）は、変換して警告を出す前に捨てる
		if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
			return '';
		}
		$v = trim( (string) $raw );
		if ( '' === $v ) {
			return '';
		}
		switch ( $type ) {
			case 'length':
				// 数値＋単位だけ。負の値は入れさせない（間隔や角丸に負は意味が無い）
				return preg_match( '/^[0-9]+(\.[0-9]+)?(px|rem|em|%)$/', $v ) ? $v : '';
			case 'ratio':
				// 1 / 1 や 16/9。空白の有無は問わないが、形はこれだけ
				return preg_match( '#^[0-9]+(\.[0-9]+)?\s*/\s*[0-9]+(\.[0-9]+)?$#', $v ) ? $v : '';
			case 'color':
				// ★ WordPress の関数に任せる。#fff / #ffffff 以外は null が返る
				$hex = sanitize_hex_color( $v );
				return is_string( $hex ) ? $hex : '';
			case 'opacity':
				// ★ **入力された文字列をそのまま返す。** (string)(float) で正規化すると、
				//   PHP 8 より前の float→文字列はロケイル依存で、他のプラグインが
				//   setlocale を呼んでいる環境では 0.8 が "0,8" になる。
				//   CSS は不正な宣言として捨てるので「入れたのに効かない」になる。
				return preg_match( '/^(0(\.[0-9]+)?|1(\.0+)?)$/', $v ) ? $v : '';
			case 'weight':
				$allowed = array( 'normal', 'bold', '100', '200', '300', '400', '500', '600', '700', '800', '900' );
				return in_array( $v, $allowed, true ) ? $v : '';
		}
		return '';
	}

	public static function add_settings_page() {
		add_options_page(
			'sonoTracks Discography',
			'sonoTracks',
			'manage_options',
			'sonotracks-discography',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'sonotracks_discography',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => array( 'slug' => '' ),
			)
		);
	}

	/**
	 * 設定の検証。
	 *
	 * ★ 入力欄には「プロフィールのURL」を貼っても「ID だけ」を入れても通るようにする。
	 *   ここを厳しくすると、URLをコピーして貼った人が理由の分からないまま弾かれる。
	 *   受け取ってから ID を取り出すほうが、人にとっては素直。
	 */
	public static function sanitize_settings( $input ) {
		// ★ 文字列であることを先に確かめる。配列を送られると (string) が
		//   PHP の警告を出したうえで "Array" になり、小文字化した "array" が
		//   ID の形に合ってしまう（そのまま保存される）。
		$raw = ( is_array( $input ) && isset( $input['slug'] ) && is_string( $input['slug'] ) )
			? trim( $input['slug'] ) : '';

		// URL で貼られた場合は最後のパス片を ID とみなす（/u/{slug}）
		if ( false !== strpos( $raw, '/' ) ) {
			$path = wp_parse_url( $raw, PHP_URL_PATH );
			if ( is_string( $path ) ) {
				$parts = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
				if ( ! empty( $parts ) ) {
					$raw = end( $parts );
				}
			}
		}

		$slug = strtolower( $raw );
		// API 側（app/api/tracks/public/artist-releases）と同じ形
		if ( '' !== $slug && ! preg_match( self::SLUG_PATTERN, $slug ) ) {
			// ★ add_settings_error() は管理画面でしか読み込まれない。通常この
			//   コールバックは options.php からしか呼ばれないが、**万一それ以外から
			//   呼ばれたときに「関数が無い」で致命的エラーにしない**。
			//   人様のサイトを白画面にする道を、念のためにも残さない。
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					self::OPTION,
					'sonotracks_dg_slug',
					'sonoTracks の ID の形式が正しくありません。プロフィールページのURL（https://sono-tracks.com/u/○○○）をそのまま貼り付けてもかまいません。',
					'error'
				);
			}
			// ★ 不正な入力で既存の設定を消さない（表示が突然消えるのを避ける）。
			//   **見た目の設定も一緒に残すこと。** ID を打ち間違えただけで、
			//   積み上げた色や余白の指定まで消えてしまうのは理不尽。
			return array(
				'slug'  => self::get_slug(),
				'style' => self::get_style(),
			);
		}

		// ★ ID を変えたら、前の ID で取った表示が残らないようキャッシュを捨てる
		if ( $slug !== self::get_slug() ) {
			self::flush_cache();
		}

		// 見た目の設定。形の合わない値は捨てて、CSS 側の既定に任せる
		$style     = array();
		$raw_style = ( is_array( $input ) && isset( $input['style'] ) && is_array( $input['style'] ) )
			? $input['style'] : array();
		$dropped   = array();
		// 色は「指定する」に印を付けたものだけを使う。色の入力欄は空にできないため
		// （空欄＝テーマに任せる、を別の印で表す）
		$use = ( is_array( $input ) && isset( $input['style_use'] ) && is_array( $input['style_use'] ) )
			? $input['style_use'] : array();
		foreach ( self::style_fields() as $key => $field ) {
			if ( 'color' === $field['type'] && ! isset( $use[ $key ] ) ) {
				continue; // 指定しない＝テーマに任せる
			}
			$given = isset( $raw_style[ $key ] ) ? $raw_style[ $key ] : '';
			$value = self::sanitize_style_value( $field['type'], $given );
			if ( '' !== $value ) {
				$style[ $key ] = $value;
			} elseif ( ( is_string( $given ) || is_numeric( $given ) ) && '' !== trim( (string) $given ) ) {
				// ★ 黙って捨てない。入れたのに効かない理由が分からないのが一番困る
				$dropped[] = $field['label'];
			}
		}
		if ( $dropped && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION,
				'sonotracks_dg_style',
				'次の項目は書き方が合わないため、元の見た目のままにしました: ' . implode( '、', $dropped ),
				'warning'
			);
		}

		return array(
			'slug'  => $slug,
			'style' => $style,
		);
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$slug  = self::get_slug();
		$style = self::get_style();
		?>
		<div class="wrap">
			<h1>sonoTracks Discography</h1>
			<p>
				sonoTracks で販売している作品の一覧を、このサイトに表示します。
				作品を追加・公開・非公開にすると、<?php echo esc_html( (string) self::CACHE_MINUTES ); ?>分以内に自動で反映されます。
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'sonotracks_discography' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="sonotracks-dg-slug">sonoTracks の ID</label>
						</th>
						<td>
							<input
								type="text"
								id="sonotracks-dg-slug"
								name="<?php echo esc_attr( self::OPTION ); ?>[slug]"
								value="<?php echo esc_attr( $slug ); ?>"
								class="regular-text"
								autocomplete="off"
								aria-describedby="sonotracks-dg-slug-help"
							/>
							<p class="description" id="sonotracks-dg-slug-help">
								sonoTracks のプロフィールページのURL（<code>https://sono-tracks.com/u/○○○</code>）の
								末尾部分です。URL をまるごと貼り付けてもかまいません。
							</p>
						</td>
					</tr>
				</table>

				<h2>見た目</h2>
				<p>
					変えたいところだけ入れてください。空欄のままにした項目は、お使いのテーマの
					見た目をそのまま引き継ぎます。色は「この色を指定する」に印を付けたものだけが
					使われます（印が無ければテーマのままです）。
				</p>
				<table class="form-table" role="presentation">
					<?php foreach ( self::style_fields() as $key => $field ) : ?>
						<?php
						$id    = 'sonotracks-dg-style-' . str_replace( '_', '-', $key );
						$value = isset( $style[ $key ] ) ? $style[ $key ] : '';
						$name  = self::OPTION . '[style][' . $key . ']';
						?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
							<td>
								<?php if ( 'color' === $field['type'] ) : ?>
									<?php
									// ★ 色は自由入力にせず選ばせる（書き方の間違いが起こらない）。
									//   使うかどうかは別の印で表す——色の入力欄は「空」にできないため。
									// ★ **入力欄を disabled にしない。** 以前は印が付くまで disabled にし、
									//   JavaScript で外していた。その形だと JS が動かない環境で、印を付けて
									//   保存しても disabled の欄は送信されず、**何も起きないうえ警告も出ない**
									//   （解除だけは動くので、設定だけが片道で壊れる）。
									//   使うかどうかはサーバー側が印で判断しているので、常に送ってよい。
									// ★ 印を先に置く。操作できるものより先に、操作の意味を読ませる。
									?>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( self::OPTION . '[style_use][' . $key . ']' ); ?>"
											value="1" <?php checked( '' !== $value ); ?> />
										この色を指定する
										<?php // 同じ文言の印が6つ並ぶので、読み上げには項目名を添える ?>
										<span class="screen-reader-text">（<?php echo esc_html( $field['label'] ); ?>）</span>
									</label>
									<input type="color" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
										value="<?php echo esc_attr( '' !== $value ? $value : '#000000' ); ?>" />
								<?php elseif ( 'weight' === $field['type'] ) : ?>
									<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>">
										<option value="">テーマに任せる</option>
										<?php foreach ( array( 'normal' => 'ふつう', 'bold' => '太字', '600' => 'やや太字（600）' ) as $v => $l ) : ?>
											<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $value, $v ); ?>><?php echo esc_html( $l ); ?></option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"
										value="<?php echo esc_attr( $value ); ?>" class="small-text"
										placeholder="<?php echo esc_attr( isset( $field['placeholder'] ) ? $field['placeholder'] : '' ); ?>"
										aria-describedby="<?php echo esc_attr( $id . '-help' ); ?>" />
									<span class="description" id="<?php echo esc_attr( $id . '-help' ); ?>">
										<?php
										echo esc_html(
											'ratio' === $field['type'] ? '例: 1 / 1、16 / 9'
												: ( 'opacity' === $field['type'] ? '0〜1（例: 0.8）'
													: '数値と単位（例: ' . ( isset( $field['placeholder'] ) ? $field['placeholder'] : '16px' ) . '）' )
										);
										?>
									</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>

			<?php self::render_status( $slug ); ?>

			<h2>使い方</h2>
			<p>投稿・固定ページに、次のショートコードを書いてください。</p>
			<p><code>[sonotracks_discography]</code></p>
			<p>1ページの件数や列数を変えたいときは、次のように書きます（件数は最大 <?php echo esc_html( (string) self::MAX_LIMIT ); ?>）。</p>
			<p><code>[sonotracks_discography limit="8" columns="4"]</code></p>
			<p>
				作品がたくさんある場合は、ページ送りを付けると<strong>すべての作品を見てもらえます</strong>。
			</p>
			<p><code>[sonotracks_discography paged="true"]</code></p>
			<p class="description">
				ページ送りは、1ページに1つの一覧でお使いください（同じページに2つ置くと、
				どちらの操作でも両方が動いてしまいます）。
			</p>
			<p>
				別の方の作品を出したい場合（レーベルのサイトなど）は、ID を直接指定できます。
			</p>
			<p><code>[sonotracks_discography slug="○○○"]</code></p>
		</div>
		<?php
	}

	/**
	 * いま何件取得できているかを設定画面に出す。
	 *
	 * ★ これが無いと、ID を打ち間違えた人は「保存はできたのに何も出ない」
	 *   状態から抜け出せない。設定した直後にその場で分かるようにしておく。
	 *
	 * ★ **ここだけキャッシュを通さず直接取りに行く。** キャッシュ越しだと、
	 *   通信できなかった場合と ID が見つからない場合がどちらも「空」になり、
	 *   API が一時的に落ちているだけの人に「ID が違います」と言ってしまう。
	 *   自分の設定が悪いのかと思って正しい ID を消してしまいかねない。
	 *   管理画面の1回きりの取得なので、都度取りに行って構わない。
	 */
	private static function render_status( $slug ) {
		if ( '' === $slug ) {
			echo '<div class="notice notice-info inline"><p>ID を設定すると、ここに取得できた作品数が表示されます。</p></div>';
			return;
		}

		// ★ 1ページ目だけ取れば足りる。API が総件数（total）も返すので、
		//   何件あるかを言うために全部を取りに行く必要は無い
		$fetched = self::fetch( $slug, self::DEFAULT_LIMIT, 1 );

		if ( null === $fetched ) {
			echo '<div class="notice notice-warning inline"><p>いま sonoTracks に接続できませんでした。ID が誤っているとは限りません。少し時間をおいて、この画面を読み込み直してください。</p></div>';
			self::render_flush_button();
			return;
		}

		$count = (int) $fetched['total'];
		if ( $count > 0 ) {
			printf(
				'<div class="notice notice-success inline"><p>%s件の作品が見つかりました。%s <a href="%s" target="_blank" rel="noopener">プロフィールを開く</a></p></div>',
				esc_html( (string) $count ),
				// 1ページに収まらない人には、ページ送りの出し方をここで伝える
				$count > self::DEFAULT_LIMIT
					? esc_html( sprintf(
						'既定では%d件までの表示です。すべて載せるには paged="true" を付けてください。',
						self::DEFAULT_LIMIT
					) )
					: '',
				esc_url( $fetched['artistUrl'] ? $fetched['artistUrl'] : 'https://sono-tracks.com/' )
			);
			self::render_flush_button();
			return;
		}

		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html(
				// artistUrl が返っている＝プロフィールは実在する（ID は合っている）
				$fetched['artistUrl']
					? 'この ID は見つかりましたが、公開中の作品がありませんでした。sonoTracks で作品を公開すると表示されます。'
					: 'この ID のプロフィールが見つかりませんでした。sonoTracks のプロフィールページのURLをご確認ください。'
			)
		);
		self::render_flush_button();
	}

	/**
	 * 「表示を今すぐ更新する」。
	 *
	 * ★ リンク（a）ではなくボタン（form + POST）にする。押すとサーバー側の
	 *   キャッシュを消すという**副作用のある操作**で、行き先を開くものではない。
	 *   リンクにすると、先読みや履歴の戻りで意図せず走りうる。
	 */
	private static function render_flush_button() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sonotracks_dg_flush" />
			<?php wp_nonce_field( 'sonotracks_dg_flush' ); ?>
			<?php submit_button( '表示を今すぐ更新する', 'secondary', 'submit', true ); ?>
		</form>
		<?php
	}

	/** プラグイン一覧から設定画面へ */
	public static function plugin_action_links( $links ) {
		$url = admin_url( 'options-general.php?page=sonotracks-discography' );
		array_unshift( $links, sprintf( '<a href="%s">設定</a>', esc_url( $url ) ) );
		return $links;
	}

	/** 「今すぐ再取得する」 */
	public static function handle_flush() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'この操作を行う権限がありません。' );
		}
		check_admin_referer( 'sonotracks_dg_flush' );
		self::flush_cache();
		wp_safe_redirect( admin_url( 'options-general.php?page=sonotracks-discography' ) );
		exit;
	}

	// ── 更新の確認 ──────────────────────────────────────────────────────

	/**
	 * 新しい版があるかを WordPress に答える（ヘッダーの Update URI と対）。
	 *
	 * ★ **wordpress.org は経由しない。** Update URI があるプラグインを WP は
	 *   wordpress.org に問い合わせないので、同名のプラグインが公開されても
	 *   別人のコードに差し替えられることがない。
	 *
	 * ★ **入れるコードの出どころを二重に確かめる。** WP は返した `package` の
	 *   URL からファイルを取って展開する。ここが差し替わると、アーティストの
	 *   サイトに任意のコードが入る。API 側も自分のURLしか返さないが、
	 *   受け取る側でも「https で、sono-tracks.com のURLか」を確かめてから渡す。
	 *   確かめられなければ**更新を出さない**（黙って何もしないほうが安全）。
	 *
	 * @param array|false $update      他のフィルタが既に返した内容
	 * @param array       $plugin_data このプラグインのヘッダー
	 * @param string      $plugin_file プラグインのファイル（例 sonotracks-discography/sonotracks-discography.php）
	 * @return array|false
	 */
	public static function check_update( $update, $plugin_data, $plugin_file ) {
		// ★ 同じ Update URI を持つ別のプラグインの問い合わせが来ることがある。
		//   自分の分でなければ、渡されたものをそのまま返す
		if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
			return $update;
		}

		$info = self::fetch_update_info();
		if ( ! is_array( $info ) ) {
			// 取れなかったときだけ、渡されたものをそのまま返す（＝何も言わない）
			return $update;
		}

		// ★ **版が同じでも配列を返す。** 新旧の比較は WordPress 側が行い
		//   （wp-includes/update.php の version_compare）、新しければ response、
		//   そうでなければ no_update に振り分ける。ここで false を返すと
		//   どちらにも載らず、プラグイン一覧で「自動更新を有効化」が
		//   **更新が出ている間しか現れない**状態になる。
		return array(
			'id'           => 'sono-tracks.com/' . self::PLUGIN_SLUG,
			'slug'         => self::PLUGIN_SLUG,
			'version'      => $info['version'],
			'url'          => $info['url'],
			'package'      => $info['package'],
			'requires'     => isset( $info['requires'] ) ? $info['requires'] : '',
			'requires_php' => isset( $info['requires_php'] ) ? $info['requires_php'] : '',
			'tested'       => isset( $info['tested'] ) ? $info['tested'] : '',
		);
	}

	/**
	 * 更新情報を取る（12時間キャッシュ）。
	 *
	 * ★ WP は管理画面を開くたびに更新の確認を回しうる。毎回 sonoTracks へ
	 *   問い合わせると、相手のサイトの管理画面が遅くなる。長めに持たせる。
	 * ★ 取れなかったときも1時間だけ「取れなかった」を覚える。覚えないと、
	 *   届かない間ずっと毎回タイムアウトを待つ。
	 */
	private static function fetch_update_info() {
		$key = self::CACHE_PREFIX . 'update';

		// ★ 「ダッシュボード → 更新 → もう一度確認する」を押したときは、
		//   キャッシュを見ずに取りに行く。押しても12時間ぶんの古い答えを
		//   返していては、**確認したのに何も起きない**（実際にそう見えた）。
		//   WordPress はこの操作を $_GET['force-check'] で伝える（update-core.php）。
		// ★ 権限を確かめる。確かめないと、front から ?force-check=1 を付けて
		//   叩くだけで、誰でもこのサイトから sonoTracks への問い合わせを
		//   何度でも起こせてしまう。
		$forced = is_admin()
			&& ! empty( $_GET['force-check'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& current_user_can( 'update_plugins' );

		$cached = $forced ? false : get_transient( $key );
		if ( is_array( $cached ) ) {
			// ★ **取り出すときにも確かめる。** 検証は取り込む時だけ、にすると、
			//   option を書ける別の経路（他のプラグインの穴など）で
			//   package を差し替えられたときに素通しになる。1行で塞げる。
			return self::is_valid_update_info( $cached ) ? $cached : null;
		}
		if ( 'none' === $cached ) {
			return null;
		}

		$origin   = defined( 'SONOTRACKS_API_ORIGIN' ) ? SONOTRACKS_API_ORIGIN : 'https://sono-tracks.com';
		$response = wp_remote_get(
			rtrim( $origin, '/' ) . '/api/tracks/public/wp-plugin-update',
			array(
				'timeout' => 5,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		$body = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $decoded ) && self::is_valid_update_info( $decoded ) ) {
				$body = $decoded;
			}
		}

		if ( null === $body ) {
			set_transient( $key, 'none', HOUR_IN_SECONDS );
			return null;
		}

		set_transient( $key, $body, 12 * HOUR_IN_SECONDS );
		return $body;
	}

	/**
	 * 更新情報として受け取ってよい形か。
	 * ★ **とくに package。** ここを緩めると、任意のURLからコードを入れる道になる。
	 */
	private static function is_valid_update_info( $info ) {
		foreach ( array( 'version', 'url', 'package' ) as $k ) {
			if ( ! isset( $info[ $k ] ) || ! is_string( $info[ $k ] ) || '' === $info[ $k ] ) {
				return false;
			}
		}
		// 版は数字と点だけ（1.2.3 / 1.2 など）
		if ( ! preg_match( '/^[0-9]+(\.[0-9]+){1,3}$/', $info['version'] ) ) {
			return false;
		}
		return self::is_own_https_url( $info['package'] ) && self::is_own_https_url( $info['url'] );
	}

	/**
	 * sonoTracks 自身の https のURLか。
	 *
	 * ★ **開発用の差し替えのために、確認をまとめて緩めない。** 以前は
	 *   SONOTRACKS_API_ORIGIN が定義されていると scheme の確認ごと飛ばしていた。
	 *   その形だと、たとえば検証環境を指すために定数を置いた本番サイトで
	 *   `http://sono-tracks.com/...` まで通ってしまい、経路の途中で
	 *   すり替えられる余地ができる。
	 *   ここは「本番の https」か「差し替え先とそっくり同じ入口（scheme・ホスト・
	 *   ポートすべて一致）」のどちらかだけを許す。
	 */
	private static function is_own_https_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		// ★ 利用者情報（user:pass@）が付いたURLは受け取らない。読み手を
		//   惑わせる形（https://sono-tracks.com@evil.com/）の入口を、
		//   ホスト名の判定に頼る前に閉じておく。
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : 0;

		// ★ ポートも見る（開発の分岐では見ているのに本番だけ見ない、を揃える）。
		//   0＝指定なし＝https の既定（443）。
		if ( 'https' === $scheme && 'sono-tracks.com' === $host && 0 === $port ) {
			return true;
		}

		if ( defined( 'SONOTRACKS_API_ORIGIN' ) ) {
			$dev = wp_parse_url( SONOTRACKS_API_ORIGIN );
			if ( is_array( $dev ) && ! empty( $dev['host'] ) && ! empty( $dev['scheme'] ) ) {
				$dev_port = isset( $dev['port'] ) ? (int) $dev['port'] : 0;
				if ( $scheme === strtolower( $dev['scheme'] )
					&& $host === strtolower( $dev['host'] )
					&& $port === $dev_port ) {
					return true;
				}
			}
		}

		return false;
	}

	// ── 表示 ────────────────────────────────────────────────────────────

	/**
	 * 見た目の設定（設定画面で入れた値）を、CSS の変数として書き出す。
	 *
	 * ★ **`wp_add_inline_style` で本体の CSS にぶら下げる。** 直接 wp_head に
	 *   echo すると、ショートコードを置いていないページにも出てしまい、
	 *   本体の CSS との前後関係も保証できない。この形なら、一覧を出す
	 *   ページでだけ、必ず本体の直後に置かれる。
	 *
	 * ★ ここは**利用者が入れた文字列を `<style>` の中へ出す**場所。
	 *   検証を通っていない値は1つも書かない（下の sanitize_style で、
	 *   型ごとに形を確かめ、外れた値は捨てている）。
	 */
	private static function inline_style() {
		$style = self::get_style();
		$decls = array();
		foreach ( self::style_fields() as $key => $field ) {
			// ★ **出すときにも検証を通す。** 保存時の検証は管理画面の保存経路
			//   （register_setting の sanitize_callback）でしか走らない。
			//   WP-CLI・cron・移行ツール・他のプラグインの update_option から
			//   書かれた値は、検証を経ずにここへ来る。`<style>` に出す場所なので、
			//   出口でももう一度確かめる。
			$value = self::sanitize_style_value(
				$field['type'],
				isset( $style[ $key ] ) ? $style[ $key ] : ''
			);
			if ( '' !== $value ) {
				$decls[] = $field['var'] . ':' . $value;
			}
		}
		return $decls ? '.sonotracks-dg{' . implode( ';', $decls ) . '}' : '';
	}

	public static function register_assets() {
		wp_register_style(
			'sonotracks-discography',
			plugins_url( 'assets/sonotracks-discography.css', __FILE__ ),
			array(),
			self::VERSION
		);
		$inline = self::inline_style();
		if ( '' !== $inline ) {
			wp_add_inline_style( 'sonotracks-discography', $inline );
		}

		// ★ **本文にショートコードがあるなら、ここで読み込む＝head に入る。**
		//   ショートコードの中で読み込むと、WordPress は既に head を出し終えて
		//   いるので footer へ回され、表示が一瞬崩れて見えることがある。
		// ★ ここで見つけられるのは本文に直接書かれた場合だけ。ウィジェットや
		//   テンプレートに置いた場合は見つからないが、**ショートコード側の
		//   読み込みが残っているので表示は崩れない**（footer になるだけ）。
		//   取りこぼしても壊れない形にしてある。
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && has_shortcode( (string) $post->post_content, 'sonotracks_discography' ) ) {
				wp_enqueue_style( 'sonotracks-discography' );
			}
		}
	}

	/**
	 * ショートコード本体。
	 *
	 * @param array $atts slug / limit / columns
	 * @return string HTML。出せるものが何も無ければ空文字。
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'slug'    => '',
				'limit'   => self::DEFAULT_LIMIT,
				'columns' => 4,
				// ページ送りを出すか。既定は出さない（1ページだけ・従来どおり）
				'paged'   => 'false',
			),
			$atts,
			'sonotracks_discography'
		);

		$slug = '' !== $atts['slug'] ? self::normalize_slug( (string) $atts['slug'] ) : self::get_slug();
		if ( '' === $slug ) {
			return '';
		}

		$limit = self::clamp( (int) $atts['limit'], self::DEFAULT_LIMIT, 1, self::MAX_LIMIT );
		// 列数はレイアウトの都合。1〜6の範囲に収める
		$columns = self::clamp( (int) $atts['columns'], 4, 1, 6 );
		$paged   = in_array( strtolower( (string) $atts['paged'] ), array( 'true', '1', 'yes', 'on' ), true );

		// ★ ページ番号はお客様のサイトのURL（?sonotracks_page=2）から読む。
		//   ページ送りを出さない指定のときは読まない——同じページに複数の一覧を
		//   置いたときに、片方の操作でもう片方まで動いてしまうため。
		$page = 1;
		if ( $paged && isset( $_GET[ self::PAGE_QUERY ] ) ) {
			$page = max( 1, (int) $_GET[ self::PAGE_QUERY ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$data = self::get_data( $slug, $limit, $page );
		if ( empty( $data['releases'] ) ) {
			// ★ 最後の砦。出せないならエラー文言も出さず、静かに何も出さない
			return '';
		}

		return self::render_grid( $data, $columns, $paged );
	}

	private static function render_grid( $data, $columns, $paged = false ) {
		// ★ 遅い enqueue でもWPが footer に回してくれる。ショートコードが
		//   使われたページでだけ読み込ませたいので、ここで呼ぶ
		wp_enqueue_style( 'sonotracks-discography' );

		$releases   = $data['releases'];
		$artist_url = $data['artistUrl'];

		ob_start();
		?>
		<div class="sonotracks-dg">
			<ul class="sonotracks-dg__list" style="--sonotracks-dg-columns: <?php echo esc_attr( (string) $columns ); ?>;">
				<?php foreach ( $releases as $release ) : ?>
					<li class="sonotracks-dg__item">
						<a class="sonotracks-dg__link" href="<?php echo esc_url( $release['url'] ); ?>" target="_blank" rel="noopener">
							<?php if ( ! empty( $release['artworkUrl'] ) ) : ?>
								<?php
								// ★ alt は空。すぐ下に作品名のテキストがあり、同じ文字列を
								//   alt に入れると読み上げが二度言うだけになる
								?>
								<img
									class="sonotracks-dg__artwork"
									src="<?php echo esc_url( $release['artworkUrl'] ); ?>"
									alt=""
									loading="lazy"
									decoding="async"
								/>
							<?php endif; ?>
							<span class="sonotracks-dg__title"><?php echo esc_html( $release['title'] ); ?></span>
							<?php if ( isset( $release['artist'] ) && '' !== $release['artist'] ) : ?>
								<span class="sonotracks-dg__artist"><?php echo esc_html( $release['artist'] ); ?></span>
							<?php endif; ?>
							<?php if ( isset( $release['priceMin'] ) ) : ?>
								<span class="sonotracks-dg__price"><?php echo esc_html( self::format_price( $release['priceMin'] ) ); ?></span>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php
			if ( $paged ) {
				echo self::render_pager( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 中で esc 済み
			}
			?>
			<?php if ( $artist_url ) : ?>
				<p class="sonotracks-dg__more">
					<a href="<?php echo esc_url( $artist_url ); ?>" target="_blank" rel="noopener">sonoTracks ですべて見る</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * ページ送り。
	 *
	 * ★ ふつうのリンク（a）で組む。JavaScript を使わないのは、記事の中に置く
	 *   部品で、JS を切っている人・読み上げ・検索エンジンのどれでも同じように
	 *   辿れるほうがよいため。プラグイン全体が「サーバー側で取って出すだけ」の
	 *   方針で揃えてある。
	 * ★ 現在地はリンクにしない（押しても何も起きないリンクを作らない）。
	 *   aria-current="page" で「いまここ」を読み上げにも伝える。
	 * ★ 行き先はいまのURLに ?sonotracks_page= を足したもの。add_query_arg は
	 *   エスケープしないので、出すときに必ず esc_url を通す。
	 */
	private static function render_pager( $data ) {
		$total_pages = max( 1, (int) $data['totalPages'] );
		if ( $total_pages < 2 ) {
			return '';
		}
		$current = min( max( 1, (int) $data['page'] ), $total_pages );

		$href = function ( $n ) {
			// 1ページ目はクエリを外す（同じ内容に2つのURLを作らない）
			return 1 === (int) $n
				? remove_query_arg( self::PAGE_QUERY )
				: add_query_arg( self::PAGE_QUERY, (int) $n );
		};

		ob_start();
		?>
		<nav class="sonotracks-dg__pager" aria-label="作品一覧のページ送り">
			<ul>
				<?php if ( $current > 1 ) : ?>
					<li class="sonotracks-dg__pagerside">
						<a href="<?php echo esc_url( $href( $current - 1 ) ); ?>" rel="prev">前へ</a>
					</li>
				<?php endif; ?>

				<?php foreach ( self::pager_numbers( $current, $total_pages ) as $n ) : ?>
					<li>
						<?php if ( null === $n ) : ?>
							<span class="sonotracks-dg__pagergap" aria-hidden="true">…</span>
						<?php elseif ( $n === $current ) : ?>
							<?php // 他の番号は「3ページ目」と読ませているので、現在地も揃える ?>
							<span class="sonotracks-dg__pagernow" aria-current="page">
								<span class="screen-reader-text"><?php echo esc_html( $n . 'ページ目' ); ?></span>
								<span aria-hidden="true"><?php echo esc_html( (string) $n ); ?></span>
							</span>
						<?php else : ?>
							<a href="<?php echo esc_url( $href( $n ) ); ?>">
								<span class="screen-reader-text"><?php echo esc_html( $n . 'ページ目' ); ?></span>
								<span aria-hidden="true"><?php echo esc_html( (string) $n ); ?></span>
							</a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>

				<?php if ( $current < $total_pages ) : ?>
					<li class="sonotracks-dg__pagerside">
						<a href="<?php echo esc_url( $href( $current + 1 ) ); ?>" rel="next">次へ</a>
					</li>
				<?php endif; ?>
			</ul>
		</nav>
		<?php
		return ob_get_clean();
	}

	/**
	 * 出すページ番号の並び。端と現在地の周りだけを出し、離れたところは null（…）。
	 * 例（現在7・全20）: 1 … 6 7 8 … 20
	 */
	private static function pager_numbers( $current, $total ) {
		$shown = array( 1, $total );
		for ( $n = $current - 1; $n <= $current + 1; $n++ ) {
			if ( $n >= 1 && $n <= $total ) {
				$shown[] = $n;
			}
		}
		$shown = array_values( array_unique( $shown ) );
		sort( $shown );

		$out  = array();
		$prev = 0;
		foreach ( $shown as $n ) {
			if ( $prev && $n - $prev === 2 ) {
				// ★ 飛びが1つだけなら、省略記号ではなくその番号を出す。
				//   「1 … 3 4 5」の … の中身が2ページ目だけ、という間の抜けた
				//   出方を避ける（sonoTracks 本体の lib/tracks/paging.ts と同じ判断）。
				$out[] = $prev + 1;
			} elseif ( $prev && $n - $prev > 2 ) {
				$out[] = null; // 飛んだところ
			}
			$out[] = $n;
			$prev  = $n;
		}
		return $out;
	}

	/** 単曲の下限値なので「〜」を付ける（¥1,000〜） */
	private static function format_price( $price_min ) {
		return sprintf( '¥%s〜', number_format_i18n( (int) $price_min ) );
	}

	// ── 取得とキャッシュ ────────────────────────────────────────────────

	/**
	 * 作品一覧を取る（transient → 最後に成功した値 → 空 の順）。
	 *
	 * @return array array( 'releases' => array, 'artistUrl' => string|null )
	 */
	public static function get_data( $slug, $limit, $page = 1 ) {
		$empty = array(
			'releases'   => array(),
			'artistUrl'  => null,
			'page'       => 1,
			'totalPages' => 1,
			'total'      => 0,
		);
		if ( '' === $slug ) {
			return $empty;
		}
		// ★ 上限で頭打ちにする。訪問者が動かせる値なので、桁の大きい番号を
		//   そのまま持ち回らない（下の保存側でも丸めた先の鍵に入れ直している）。
		$page = max( 1, min( (int) $page, self::MAX_PAGE ) );

		// ★ ここでも丸める。ショートコード側でも丸めているが、この関数は公開で、
		//   テーマから直接呼ばれうる。範囲外のまま通すと API 側で丸められた結果と
		//   鍵が食い違い、同じ内容を別々にキャッシュすることになる。
		$limit = self::clamp( (int) $limit, self::DEFAULT_LIMIT, 1, self::MAX_LIMIT );

		$transient_key = self::transient_key( $slug, $limit, $page );
		$option_key    = self::option_key( $slug, $limit, $page );

		$cached = get_transient( $transient_key );
		if ( is_array( $cached ) ) {
			return self::normalize_data( $cached );
		}

		$fetched = self::fetch( $slug, $limit, $page );
		if ( is_array( $fetched ) ) {
			// ★★ **要求されたページ番号の鍵で保存しない。丸められた先の鍵に入れる。**
			//   ページ番号は訪問者が URL（?sonotracks_page=）で自由に動かせる。
			//   範囲外のページは API が最後のページに丸めて「作品入りの成功」を返すため、
			//   要求された番号のまま保存すると、?sonotracks_page=1000, 1001, 1002 …と
			//   叩かれるたびに**無期限の option が1行ずつ増え、相手のサイトの
			//   wp_options が際限なく太る**（クローラーの連番アクセスでも起きる）。
			//   丸めた先を鍵にすれば、実在するページのぶんしか鍵は生まれない。
			// ★ 鍵に入る値のうち、訪問者が動かせるのはページ番号だけ。ここを塞げば
			//   鍵の総数は「ID × 件数 × 実在するページ数」で頭打ちになる。
			$store_page = max( 1, (int) $fetched['page'] );
			if ( $store_page !== $page ) {
				$transient_key = self::transient_key( $slug, $limit, $store_page );
				$option_key    = self::option_key( $slug, $limit, $store_page );
			}
			set_transient( $transient_key, $fetched, self::CACHE_MINUTES * MINUTE_IN_SECONDS );

			// ★ 無期限の last-good は、原則「中身がある時だけ」更新する。空で上書きすると
			//   障害時の保険そのものが消えるため。
			// ★ **ただし0件が確かな場合は空で上書きする。** artistUrl が返っている＝
			//   プロフィールは実在し、公開中の作品が本当に0件だと確定している。
			//   ここを常に守りに倒すと、作品を非公開にした・**運営が権利侵害で
			//   取り下げた**あとに sono 側で障害が起きたとき、消したはずの作品が
			//   相手のサイトに復活する。権利対応でそれは起こしてはいけない。
			//   artistUrl が null（＝相手が見つからない）ときだけ、改名や一時的な
			//   食い違いの可能性を見て保険を残す。
			$confirmed_empty = empty( $fetched['releases'] ) && ! empty( $fetched['artistUrl'] );
			if ( ! empty( $fetched['releases'] ) || $confirmed_empty ) {
				update_option( $option_key, $fetched, false );
			}
			return self::normalize_data( $fetched );
		}

		// ★ 取得失敗。期限切れでも構わないので最後に成功した値を出す
		$last     = get_option( $option_key, array() );
		$fallback = is_array( $last ) ? $last : array();

		// ★ 失敗の結果も1分だけ transient に入れる。入れないと、API に届かない間
		//   すべてのページ表示が毎回タイムアウトを待つことになり、
		//   sonoTracks の障害がお客様のサイト全体を重くしてしまう
		set_transient( $transient_key, $fallback, MINUTE_IN_SECONDS );
		return self::normalize_data( $fallback );
	}

	/**
	 * 公開APIを叩く。
	 *
	 * @return array|null 成功時は array( 'releases' => array, 'artistUrl' => string|null )。失敗時 null。
	 */
	private static function fetch( $slug, $limit, $page = 1 ) {
		// ★ 開発・ステージングで叩き先を差し替えられるように。未定義なら本番
		$origin = defined( 'SONOTRACKS_API_ORIGIN' ) ? SONOTRACKS_API_ORIGIN : 'https://sono-tracks.com';

		$url = add_query_arg(
			array(
				'slug'  => $slug,
				'limit' => (int) $limit,
				'page'  => max( 1, (int) $page ),
			),
			rtrim( $origin, '/' ) . '/api/tracks/public/artist-releases'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 5,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}
		// ★ 成功判定は200のみ。sonoTracks 側は障害時に200ではなく503を返す
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['releases'] ) || ! is_array( $body['releases'] ) ) {
			return null;
		}

		// ★ 形の合わない項目は捨てる。**isset だけでは足りない。**
		//   title や url が（API の形が変わる等で）配列で来ると、表示側の
		//   esc_html()/esc_url() が PHP 8 で TypeError になり、
		//   **相手サイトのそのページ全体が 500 になる**。
		//   「本文にエラー文言を出さない」と言いながら最悪が fatal では意味が無いので、
		//   文字列であることまで確かめる。任意項目（artist・artworkUrl・priceMin）も
		//   表示側が触る前にここで落とす。
		$releases = array();
		foreach ( $body['releases'] as $release ) {
			if ( ! is_array( $release ) ) {
				continue;
			}
			if ( ! isset( $release['url'], $release['title'] ) ) {
				continue;
			}
			if ( ! is_string( $release['url'] ) || ! is_string( $release['title'] ) ) {
				continue;
			}
			if ( isset( $release['artist'] ) && ! is_string( $release['artist'] ) ) {
				unset( $release['artist'] );
			}
			if ( isset( $release['artworkUrl'] ) && ! is_string( $release['artworkUrl'] ) ) {
				unset( $release['artworkUrl'] );
			}
			if ( isset( $release['priceMin'] ) && ! is_numeric( $release['priceMin'] ) ) {
				unset( $release['priceMin'] );
			}
			$releases[] = $release;
		}

		// ★ ページ送りの値は、無い・数でない場合に既定へ倒す。古い版の sonoTracks や
		//   古い形式のキャッシュが混ざっても、呼び出し側が壊れないようにする
		//   （その場合はページ送りが出ないだけで、一覧はそのまま表示される）。
		$total_pages = ( isset( $body['totalPages'] ) && is_numeric( $body['totalPages'] ) )
			? max( 1, (int) $body['totalPages'] ) : 1;
		return array(
			'releases'   => $releases,
			'artistUrl'  => ( isset( $body['artistUrl'] ) && is_string( $body['artistUrl'] ) ) ? $body['artistUrl'] : null,
			'page'       => ( isset( $body['page'] ) && is_numeric( $body['page'] ) ) ? max( 1, (int) $body['page'] ) : 1,
			'totalPages' => $total_pages,
			'total'      => ( isset( $body['total'] ) && is_numeric( $body['total'] ) )
				? max( 0, (int) $body['total'] ) : count( $releases ),
		);
	}

	/** 形が崩れていても、呼び出し側で isset を書かずに済むよう必ず同じ形にする */
	private static function normalize_data( $data ) {
		$empty = array(
			'releases'   => array(),
			'artistUrl'  => null,
			'page'       => 1,
			'totalPages' => 1,
			'total'      => 0,
		);
		if ( ! is_array( $data ) || ! isset( $data['releases'] ) || ! is_array( $data['releases'] ) ) {
			return $empty;
		}
		if ( ! isset( $data['artistUrl'] ) || ! is_string( $data['artistUrl'] ) ) {
			$data['artistUrl'] = null;
		}
		// 旧版のキャッシュ（ページ送りの値を持たない）が残っていても壊れないように
		foreach ( array( 'page' => 1, 'totalPages' => 1, 'total' => count( $data['releases'] ) ) as $k => $default ) {
			if ( ! isset( $data[ $k ] ) || ! is_numeric( $data[ $k ] ) ) {
				$data[ $k ] = $default;
			} else {
				$data[ $k ] = (int) $data[ $k ];
			}
		}
		return $data;
	}

	/** いまの世代。壊れた値でも必ず正の整数にする */
	private static function cache_version() {
		return max( 1, (int) get_option( self::VERSION_OPTION, 1 ) );
	}

	/**
	 * 10分で切れるほうの鍵。**世代を混ぜる**ので、世代を進めれば全部が無効になる。
	 */
	private static function transient_key( $slug, $limit, $page ) {
		return self::CACHE_PREFIX . md5(
			self::cache_version() . '|' . $slug . '|' . (int) $limit . '|' . (int) $page
		);
	}

	/**
	 * 障害時の保険（last-good）の鍵。**世代を混ぜない。**
	 * 混ぜると「更新」を押した直後に sonoTracks が落ちていたとき、保険まで
	 * 巻き添えで消えて何も出せなくなる。
	 */
	private static function option_key( $slug, $limit, $page ) {
		return self::CACHE_PREFIX . md5( $slug . '|' . (int) $limit . '|' . (int) $page ) . '_last';
	}

	/**
	 * キャッシュを捨てる（＝世代を1つ進める）。
	 *
	 * ★ 鍵が（ID × 件数 × ページ）に増えたので、総当たりでは追えない。
	 *   世代を進めれば、どの組み合わせも一度に無効になる。
	 * ★ ショートコードで別の ID を直接指定している一覧にも効く（以前は
	 *   設定した ID のぶんしか消せていなかった）。
	 */
	public static function flush_cache() {
		update_option( self::VERSION_OPTION, self::cache_version() + 1, true );
		// ★ 更新情報の鍵には世代を混ぜていない（固定鍵）ので、ここで消す。
		//   消さないと、新しい版を出しても最悪13時間ほど「更新があります」が
		//   出ず、急ぎの修正を届けたいときに手立てが無くなる。
		delete_transient( self::CACHE_PREFIX . 'update' );
	}

	// ── 小物 ────────────────────────────────────────────────────────────

	private static function normalize_slug( $raw ) {
		$slug = strtolower( trim( (string) $raw ) );
		return preg_match( self::SLUG_PATTERN, $slug ) ? $slug : '';
	}

	private static function clamp( $value, $default, $min, $max ) {
		if ( $value <= 0 ) {
			return $default;
		}
		return max( $min, min( $max, $value ) );
	}
}

SonoTracks_Discography::init();

endif;
