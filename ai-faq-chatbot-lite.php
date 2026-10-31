<?php
/**
 * Plugin Name:       AI FAQ Chatbot Lite
 * Plugin URI:        https://github.com/Aliakbar45480/ai-faq-chatbot-lite
 * Description:       A lightweight chat widget that answers visitor questions from your own FAQ, 24/7. Works offline with smart matching, or plug in an OpenAI API key for natural AI answers grounded in your FAQ.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Ali Akbar (BD Webhut)
 * Author URI:        https://github.com/Aliakbar45480
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-faq-chatbot-lite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AIFAQ_Bot {

	const OPTION = 'aifaq_settings';
	const NS     = 'ai-faq-bot/v1';

	public static function defaults() {
		return array(
			'enabled'  => 1,
			'name'     => 'Assistant',
			'welcome'  => 'Hi! 👋 Ask me anything about our services, prices or delivery.',
			'fallback' => "Sorry, I don't have an answer for that yet. Please contact us and a human will help you.",
			'contact'  => '',
			'color'    => '#2e6bf7',
			'faq'      => "Q: What services do you offer?\nA: We design and build WordPress websites, online stores and AI chatbots.\n\nQ: How long does a website take?\nA: Most business websites are ready in 1–3 weeks, depending on the number of pages.\n\nQ: How much does a website cost?\nA: Prices start from a fixed package. Send us your requirements for an exact quote.",
			'api_key'  => '',
			'model'    => 'gpt-4o-mini',
			'limit'    => 30,
		);
	}

	public static function opts() {
		return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'links' ) );
		if ( self::opts()['enabled'] ) {
			add_action( 'wp_footer', array( __CLASS__, 'widget' ) );
		}
	}

	/* ---------------- FAQ parsing & matching ---------------- */

	public static function faq_items() {
		$items = array();
		$text  = str_replace( "\r", '', (string) self::opts()['faq'] );
		foreach ( preg_split( '/\n\s*\n/', $text ) as $block ) {
			if ( preg_match( '/^\s*Q:\s*(.+?)\n\s*A:\s*(.+)$/is', trim( $block ), $m ) ) {
				$items[] = array( 'q' => trim( $m[1] ), 'a' => trim( $m[2] ) );
			}
		}
		return $items;
	}

	private static function tokens( $s ) {
		$stop = array( 'the', 'a', 'an', 'is', 'are', 'do', 'does', 'you', 'your', 'i', 'me', 'my', 'we', 'our', 'to', 'of', 'for', 'and', 'or', 'in', 'on', 'it', 'can', 'how', 'what', 'when', 'where', 'which', 'who', 'why', 'with', 'be', 'have', 'has', 'there', 'any', 'please', 'about' );
		$words = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $s ), -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( $words as $w ) {
			if ( mb_strlen( $w ) < 2 || in_array( $w, $stop, true ) ) {
				continue;
			}
			// Light stemming so "prices" matches "price" and "delivering" matches "deliver".
			if ( mb_strlen( $w ) > 4 && preg_match( '/ies$/u', $w ) ) {
				$w = preg_replace( '/ies$/u', 'y', $w );
			} elseif ( mb_strlen( $w ) > 5 && preg_match( '/ing$/u', $w ) ) {
				$w = preg_replace( '/ing$/u', '', $w );
			} elseif ( mb_strlen( $w ) > 3 && preg_match( '/[^s]s$/u', $w ) ) {
				$w = mb_substr( $w, 0, -1 );
			}
			$out[] = $w;
		}
		return array_unique( $out );
	}

	public static function best_match( $question ) {
		$qt   = self::tokens( $question );
		$best = null;
		$top  = 0;
		if ( ! $qt ) {
			return null;
		}
		foreach ( self::faq_items() as $item ) {
			$ft    = self::tokens( $item['q'] . ' ' . $item['q'] . ' ' . $item['a'] );
			$hit   = count( array_intersect( $qt, $ft ) );
			$score = $hit / max( 1, count( $qt ) );
			if ( $score > $top ) {
				$top  = $score;
				$best = $item;
			}
		}
		return $top >= 0.34 ? $best : null;
	}

	/* ---------------- REST endpoint ---------------- */

	public static function routes() {
		register_rest_route(
			self::NS,
			'/ask',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'ask' ),
				'args'                => array(
					'question' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	public static function ask( WP_REST_Request $req ) {
		$o = self::opts();
		$q = trim( wp_strip_all_tags( (string) $req->get_param( 'question' ) ) );
		if ( '' === $q || mb_strlen( $q ) > 500 ) {
			return new WP_REST_Response( array( 'answer' => 'Please type a shorter question.' ), 400 );
		}

		// Simple per-visitor rate limit (per hour).
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'x';
		$key   = 'aifaq_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= (int) $o['limit'] ) {
			return array( 'answer' => 'You have reached the question limit for now. Please try again later or contact us directly.' );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		if ( ! empty( $o['api_key'] ) ) {
			$ai = self::ask_ai( $q, $o );
			if ( $ai ) {
				return array( 'answer' => $ai, 'source' => 'ai' );
			}
		}
		$m = self::best_match( $q );
		return array(
			'answer' => $m ? $m['a'] : $o['fallback'],
			'source' => $m ? 'faq' : 'fallback',
		);
	}

	private static function ask_ai( $q, $o ) {
		$faq = '';
		foreach ( self::faq_items() as $i ) {
			$faq .= 'Q: ' . $i['q'] . "\nA: " . $i['a'] . "\n\n";
		}
		$system = 'You are ' . $o['name'] . ', a friendly assistant for the website "' . get_bloginfo( 'name' ) . '". '
			. 'Answer ONLY using the FAQ below. Keep answers short (max 3 sentences) and answer in the language of the question. '
			. 'If the FAQ does not contain the answer, reply exactly: ' . $o['fallback'] . "\n\nFAQ:\n" . $faq;

		$res = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $o['api_key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $o['model'],
						'temperature' => 0.2,
						'max_tokens'  => 250,
						'messages'    => array(
							array( 'role' => 'system', 'content' => $system ),
							array( 'role' => 'user', 'content' => $q ),
						),
					)
				),
			)
		);
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return null; // Falls back to FAQ matching.
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return isset( $body['choices'][0]['message']['content'] ) ? sanitize_textarea_field( $body['choices'][0]['message']['content'] ) : null;
	}

	/* ---------------- Admin ---------------- */

	public static function menu() {
		add_options_page( 'AI FAQ Chatbot', 'AI FAQ Chatbot', 'manage_options', 'aifaq', array( __CLASS__, 'page' ) );
	}

	public static function links( $l ) {
		array_unshift( $l, '<a href="' . esc_url( admin_url( 'options-general.php?page=aifaq' ) ) . '">Settings</a>' );
		return $l;
	}

	public static function register() {
		register_setting( 'aifaq', self::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ) {
		$d   = self::defaults();
		$old = self::opts();
		return array(
			'enabled'  => empty( $in['enabled'] ) ? 0 : 1,
			'name'     => sanitize_text_field( $in['name'] ?? $d['name'] ),
			'welcome'  => sanitize_text_field( $in['welcome'] ?? $d['welcome'] ),
			'fallback' => sanitize_text_field( $in['fallback'] ?? $d['fallback'] ),
			'contact'  => esc_url_raw( $in['contact'] ?? '' ),
			'color'    => sanitize_hex_color( $in['color'] ?? '' ) ? sanitize_hex_color( $in['color'] ) : $d['color'],
			'faq'      => sanitize_textarea_field( $in['faq'] ?? '' ),
			// Keep the saved key when the field is left blank.
			'api_key'  => ( isset( $in['api_key'] ) && '' !== trim( $in['api_key'] ) ) ? sanitize_text_field( $in['api_key'] ) : $old['api_key'],
			'model'    => sanitize_text_field( $in['model'] ?? $d['model'] ),
			'limit'    => max( 1, absint( $in['limit'] ?? $d['limit'] ) ),
		);
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = self::opts();
		$n = self::OPTION;
		$c = count( self::faq_items() );
		?>
		<div class="wrap">
			<h1>AI FAQ Chatbot Lite</h1>
			<p>Write your FAQ once. The chat widget answers visitors 24/7. <strong><?php echo (int) $c; ?></strong> FAQ entries detected.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'aifaq' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Enable widget</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( $o['enabled'] ); ?> /> Show the chat bubble on the site</label></td></tr>
					<tr><th scope="row"><label for="aifaq_faq">FAQ</label></th><td>
						<textarea id="aifaq_faq" class="large-text code" rows="14" name="<?php echo esc_attr( $n ); ?>[faq]"><?php echo esc_textarea( $o['faq'] ); ?></textarea>
						<p class="description">Format: <code>Q: question</code> on one line, <code>A: answer</code> on the next, blank line between entries.</p></td></tr>
					<tr><th scope="row"><label for="aifaq_name">Bot name</label></th><td><input id="aifaq_name" class="regular-text" name="<?php echo esc_attr( $n ); ?>[name]" value="<?php echo esc_attr( $o['name'] ); ?>" /></td></tr>
					<tr><th scope="row"><label for="aifaq_welcome">Welcome message</label></th><td><input id="aifaq_welcome" class="large-text" name="<?php echo esc_attr( $n ); ?>[welcome]" value="<?php echo esc_attr( $o['welcome'] ); ?>" /></td></tr>
					<tr><th scope="row"><label for="aifaq_fallback">No-answer message</label></th><td><input id="aifaq_fallback" class="large-text" name="<?php echo esc_attr( $n ); ?>[fallback]" value="<?php echo esc_attr( $o['fallback'] ); ?>" /></td></tr>
					<tr><th scope="row"><label for="aifaq_contact">Contact page URL</label></th><td><input id="aifaq_contact" class="regular-text" name="<?php echo esc_attr( $n ); ?>[contact]" value="<?php echo esc_attr( $o['contact'] ); ?>" placeholder="https://example.com/contact" />
						<p class="description">Shown as a "Talk to a human" link in the chat.</p></td></tr>
					<tr><th scope="row"><label for="aifaq_color">Color</label></th><td><input id="aifaq_color" type="color" name="<?php echo esc_attr( $n ); ?>[color]" value="<?php echo esc_attr( $o['color'] ); ?>" /></td></tr>
					<tr><th scope="row"><label for="aifaq_key">OpenAI API key (optional)</label></th><td>
						<input id="aifaq_key" type="password" class="regular-text" name="<?php echo esc_attr( $n ); ?>[api_key]" value="" autocomplete="new-password" placeholder="<?php echo $o['api_key'] ? esc_attr__( '•••••• saved — leave blank to keep', 'ai-faq-chatbot-lite' ) : 'sk-...'; ?>" />
						<p class="description">Without a key the bot uses fast keyword matching. With a key, answers are written by AI but only from your FAQ. The key is stored on your server and never sent to the browser.</p></td></tr>
					<tr><th scope="row"><label for="aifaq_model">AI model</label></th><td><input id="aifaq_model" class="regular-text" name="<?php echo esc_attr( $n ); ?>[model]" value="<?php echo esc_attr( $o['model'] ); ?>" /></td></tr>
					<tr><th scope="row"><label for="aifaq_limit">Questions per visitor / hour</label></th><td><input id="aifaq_limit" type="number" min="1" class="small-text" name="<?php echo esc_attr( $n ); ?>[limit]" value="<?php echo esc_attr( $o['limit'] ); ?>" /></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ---------------- Front-end widget ---------------- */

	public static function widget() {
		$o   = self::opts();
		$cfg = array(
			'endpoint' => esc_url_raw( rest_url( self::NS . '/ask' ) ),
			'name'     => $o['name'],
			'welcome'  => $o['welcome'],
			'contact'  => $o['contact'],
			'chips'    => array_slice( wp_list_pluck( self::faq_items(), 'q' ), 0, 3 ),
		);
		$color = esc_attr( $o['color'] );
		?>
		<div id="aifaq" style="--aifaq:<?php echo $color; // phpcs:ignore -- escaped above. ?>">
			<button type="button" class="aifaq-bubble" aria-label="Open chat"><svg viewBox="0 0 24 24"><path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2z"/></svg></button>
			<div class="aifaq-panel" role="dialog" aria-label="Chat" hidden>
				<div class="aifaq-head"><span class="aifaq-dot"></span><b></b><button type="button" class="aifaq-x" aria-label="Close">×</button></div>
				<div class="aifaq-log" aria-live="polite"></div>
				<div class="aifaq-chips"></div>
				<form class="aifaq-form"><input type="text" maxlength="500" placeholder="Type your question…" aria-label="Your question" required /><button type="submit" aria-label="Send">➤</button></form>
			</div>
		</div>
		<style>
			#aifaq{position:fixed;right:20px;bottom:20px;z-index:99999;font:14px/1.45 system-ui,-apple-system,sans-serif}
			#aifaq *{box-sizing:border-box}
			.aifaq-bubble{width:58px;height:58px;border-radius:50%;border:0;background:var(--aifaq);cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,.2);display:flex;align-items:center;justify-content:center;transition:transform .2s}
			.aifaq-bubble:hover{transform:scale(1.07)}.aifaq-bubble svg{width:26px;height:26px;fill:#fff}
			.aifaq-panel{position:absolute;right:0;bottom:72px;width:340px;max-width:calc(100vw - 40px);height:460px;max-height:calc(100vh - 120px);background:#fff;color:#1b1f2e;border-radius:16px;box-shadow:0 16px 48px rgba(0,0,0,.22);display:flex;flex-direction:column;overflow:hidden}
			.aifaq-panel[hidden]{display:none}
			.aifaq-head{background:var(--aifaq);color:#fff;padding:12px 14px;display:flex;align-items:center;gap:8px}
			.aifaq-dot{width:9px;height:9px;border-radius:50%;background:#7CFFB2}.aifaq-x{margin-left:auto;background:none;border:0;color:#fff;font-size:22px;cursor:pointer;line-height:1}
			.aifaq-log{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:8px;background:#f6f7fb}
			.aifaq-msg{max-width:85%;padding:9px 12px;border-radius:14px;white-space:pre-wrap;word-wrap:break-word}
			.aifaq-bot{background:#fff;border:1px solid #e6e8f0;align-self:flex-start;border-bottom-left-radius:4px}
			.aifaq-me{background:var(--aifaq);color:#fff;align-self:flex-end;border-bottom-right-radius:4px}
			.aifaq-msg a{color:inherit;font-weight:600}
			.aifaq-chips{display:flex;flex-wrap:wrap;gap:6px;padding:8px 12px 0;background:#f6f7fb}
			.aifaq-chips button{border:1px solid var(--aifaq);color:var(--aifaq);background:#fff;border-radius:99px;padding:4px 10px;font-size:12px;cursor:pointer}
			.aifaq-form{display:flex;gap:6px;padding:10px;border-top:1px solid #eceef4;background:#fff}
			.aifaq-form input{flex:1;border:1px solid #dfe2ec;border-radius:10px;padding:9px 11px;font:inherit;color:#1b1f2e;background:#fff}
			.aifaq-form button{border:0;background:var(--aifaq);color:#fff;border-radius:10px;padding:0 14px;cursor:pointer}
			.aifaq-typing{opacity:.6;font-style:italic}
		</style>
		<script>
		(function(){
			var C=<?php echo wp_json_encode( $cfg ); ?>, root=document.getElementById('aifaq');
			var panel=root.querySelector('.aifaq-panel'), log=root.querySelector('.aifaq-log'), form=root.querySelector('form'), input=form.querySelector('input'), chips=root.querySelector('.aifaq-chips');
			root.querySelector('.aifaq-head b').textContent=C.name;
			function add(text,who,cls){var d=document.createElement('div');d.className='aifaq-msg aifaq-'+who+(cls?' '+cls:'');d.textContent=text;log.appendChild(d);log.scrollTop=log.scrollHeight;return d;}
			function human(){if(!C.contact)return;var d=document.createElement('div');d.className='aifaq-msg aifaq-bot';var a=document.createElement('a');a.href=C.contact;a.textContent='Talk to a human →';d.appendChild(a);log.appendChild(d);log.scrollTop=log.scrollHeight;}
			var opened=false;
			function toggle(){panel.hidden=!panel.hidden;if(!panel.hidden){if(!opened){add(C.welcome,'bot');opened=true;}input.focus();}}
			root.querySelector('.aifaq-bubble').onclick=toggle;root.querySelector('.aifaq-x').onclick=toggle;
			(C.chips||[]).forEach(function(q){var b=document.createElement('button');b.type='button';b.textContent=q;b.onclick=function(){send(q);};chips.appendChild(b);});
			function send(q){q=(q||'').trim();if(!q)return;chips.innerHTML='';add(q,'me');input.value='';var t=add('Typing…','bot','aifaq-typing');
				fetch(C.endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({question:q})})
				.then(function(r){return r.json();}).then(function(j){t.remove();add(j.answer||'…','bot');if(j.source==='fallback')human();})
				.catch(function(){t.remove();add('Connection problem. Please try again.','bot');});}
			form.onsubmit=function(e){e.preventDefault();send(input.value);};
		})();
		</script>
		<?php
	}
}

add_action( 'plugins_loaded', array( 'AIFAQ_Bot', 'init' ) );
