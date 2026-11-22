<?php
/**
 * Plugin Name:       AI FAQ Chatbot Lite
 * Plugin URI:        https://github.com/aliakbarrme/ai-faq-chatbot-lite
 * Description:       A modern chat widget that answers visitor questions from your own FAQ, 24/7. Works offline with smart matching, or connect a free Google Gemini / Groq key (or OpenAI) for natural AI answers grounded in your FAQ.
 * Version:           2.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Ali Akbar (BD Webhut)
 * Author URI:        https://github.com/aliakbarrme
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-faq-chatbot-lite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AIFAQ_Bot {

	const OPTION  = 'aifaq_settings';
	const NS      = 'ai-faq-bot/v1';
	const VERSION = '2.0.0';

	/** Default model per AI provider. */
	const MODELS = array(
		'gemini' => 'gemini-2.5-flash',
		'groq'   => 'llama-3.3-70b-versatile',
		'openai' => 'gpt-4o-mini',
	);

	public static function defaults() {
		return array(
			'enabled'  => 1,
			'name'     => 'Assistant',
			'subtitle' => 'Online · Typically replies instantly',
			'welcome'  => "Hi there 👋\nI can answer questions about our services, pricing and timelines. What can I help you with?",
			'fallback' => "I'm not sure about that one yet. Our team can help you directly.",
			'teaser'   => '👋 Have a question? Ask me anything.',
			'contact'  => '',
			'whatsapp' => '',
			'avatar'   => '',
			'color'    => '#2e6bf7',
			'theme'    => 'light',
			'position' => 'right',
			'chips'    => 4,
			'footer'   => '',
			'faq'      => "Q: What services do you offer?\nA: We design and build WordPress websites, online stores and AI chatbots.\n\nQ: How long does a website take?\nQ: How many days do you need?\nA: Most business websites are ready in 1–3 weeks, depending on the number of pages.\n\nQ: How much does a website cost?\nA: Prices start from a fixed package. Send us your requirements for an exact quote.",
			'provider' => 'none',
			'api_key'  => '',
			'model'    => '',
			'limit'    => 30,
		);
	}

	public static function opts() {
		$saved = get_option( self::OPTION, array() );
		$o     = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		// v1 stored only an OpenAI key: keep it working after the update.
		if ( ! isset( $saved['provider'] ) && ! empty( $o['api_key'] ) ) {
			$o['provider'] = 'openai';
		}
		return $o;
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

	/* ---------------- FAQ parsing ---------------- */

	/**
	 * One entry = one or more "Q:" lines (different ways to ask) + an "A:" answer.
	 * The answer may continue over several lines. Entries are separated by a blank line.
	 */
	public static function faq_items() {
		$items = array();
		$text  = str_replace( "\r", '', (string) self::opts()['faq'] );
		foreach ( preg_split( '/\n\s*\n/', $text ) as $block ) {
			$qs  = array();
			$ans = array();
			$in  = false;
			foreach ( explode( "\n", trim( $block ) ) as $line ) {
				if ( ! $in && preg_match( '/^\s*Q\s*:\s*(.+)$/i', $line, $m ) ) {
					$qs[] = trim( $m[1] );
				} elseif ( preg_match( '/^\s*A\s*:\s*(.*)$/i', $line, $m ) && ! $in ) {
					$in    = true;
					$ans[] = trim( $m[1] );
				} elseif ( $in ) {
					$ans[] = rtrim( $line );
				}
			}
			$a = trim( implode( "\n", $ans ) );
			if ( $qs && '' !== $a ) {
				$items[] = array(
					'q'    => $qs[0],
					'alts' => $qs,
					'a'    => $a,
				);
			}
		}
		return $items;
	}

	/* ---------------- Smart offline matching ---------------- */

	/** Words that mean the same thing (English + Bangla + Banglish) map to one key. */
	private static function synonyms() {
		return array(
			'price'     => array( 'price', 'cost', 'costs', 'pricing', 'fee', 'fees', 'charge', 'charges', 'rate', 'rates', 'budget', 'quote', 'quotation', 'package', 'packages', 'expensive', 'cheap', 'afford', 'dam', 'daam', 'koto', 'taka', 'tk', 'khoroch', 'khorc', 'kharach', 'dollar', 'usd', 'bdt', 'দাম', 'খরচ', 'টাকা', 'কত', 'প্যাকেজ' ),
			'payment'   => array( 'payment', 'payments', 'pay', 'paying', 'advance', 'upfront', 'deposit', 'half', 'installment', 'milestone', 'invoice', 'method', 'methods', 'paypal', 'payoneer', 'wise', 'bkash', 'nagad', 'bank', 'transfer', 'পেমেন্ট' ),
			'company'   => array( 'company', 'founder', 'founders', 'ceo', 'chairman', 'director', 'behind', 'about', 'background', 'history', 'team', 'কোম্পানি' ),
			'time'      => array( 'time', 'long', 'duration', 'days', 'day', 'week', 'weeks', 'month', 'deadline', 'soon', 'turnaround', 'delivery', 'deliver', 'ready', 'finish', 'complete', 'somoy', 'koydin', 'koto', 'din', 'shomoy', 'সময়', 'দিন', 'কতদিন' ),
			'portfolio' => array( 'portfolio', 'previous', 'past', 'work', 'works', 'sample', 'samples', 'example', 'examples', 'project', 'projects', 'case', 'demo', 'show', 'showcase', 'clients', 'ager', 'dekhan', 'dekhte', 'আগের', 'কাজ', 'পোর্টফোলিও' ),
			'contact'   => array( 'contact', 'call', 'phone', 'number', 'email', 'mail', 'whatsapp', 'reach', 'talk', 'human', 'person', 'agent', 'jogajog', 'যোগাযোগ', 'ফোন', 'নম্বর' ),
			'location'  => array( 'location', 'located', 'office', 'address', 'based', 'country', 'bangladesh', 'rajshahi', 'kothay', 'kothai', 'অফিস', 'কোথায়' ),
			'meeting'   => array( 'meeting', 'meet', 'consultation', 'consult', 'zoom', 'discuss', 'discussion' ),
			'app'       => array( 'app', 'apps', 'android', 'ios', 'iphone', 'flutter', 'apk', 'playstore', 'অ্যাপ' ),
			'logo'      => array( 'logo', 'branding', 'brand', 'graphic', 'graphics', 'banner', 'flyer', 'poster', 'identity' ),
			'video'     => array( 'video', 'videos', 'reel', 'reels', 'editing', 'edit', 'motion', 'animation', 'explainer', 'promo' ),
			'refund'    => array( 'refund', 'refundable', 'money', 'back', 'cancel', 'cancellation', 'ferot' ),
			'owner'     => array( 'own', 'owner', 'ownership', 'source', 'code', 'files', 'copyright' ),
			'lead'      => array( 'lead', 'leads', 'prospect', 'prospects', 'b2b', 'list' ),
			'scrape'    => array( 'scrape', 'scraping', 'scraper', 'extract', 'extraction', 'crawl', 'crawler' ),
			'nda'       => array( 'nda', 'confidential', 'confidentiality', 'secret', 'safe', 'privacy' ),
			'urgent'    => array( 'urgent', 'urgently', 'asap', 'rush', 'express', 'emergency', 'today', 'tonight', 'tomorrow', 'fast', 'quick', 'quickly', 'joruri', 'ajke', 'ajkei', 'kalke', 'জরুরি', 'আজকেই' ),
			'design'    => array( 'figma', 'ui', 'ux', 'wireframe', 'wireframes', 'prototype', 'mockup' ),
			'service'   => array( 'service', 'services', 'offer', 'provide', 'build', 'make', 'create', 'develop', 'development', 'kaj', 'koren', 'সেবা', 'সার্ভিস' ),
			'website'   => array( 'website', 'site', 'web', 'webpage', 'page', 'landing', 'wordpress', 'wp', 'ওয়েবসাইট' ),
			'shop'      => array( 'shop', 'store', 'ecommerce', 'commerce', 'woocommerce', 'woo', 'online', 'sell', 'selling', 'cart', 'product', 'products' ),
			'support'   => array( 'support', 'maintenance', 'maintain', 'update', 'updates', 'fix', 'bug', 'help', 'after', 'care', 'সাপোর্ট' ),
			'revision'  => array( 'revision', 'revisions', 'change', 'changes', 'modify', 'unlimited' ),
			'seo'       => array( 'seo', 'google', 'rank', 'ranking', 'search', 'traffic' ),
			'speed'     => array( 'speed', 'slow', 'loading', 'performance', 'optimize', 'optimization', 'pagespeed' ),
			'hosting'   => array( 'hosting', 'host', 'server', 'domain', 'ssl', 'cpanel' ),
			'start'     => array( 'start', 'started', 'begin', 'process', 'steps', 'onboarding', 'requirement', 'requirements', 'shuru', 'শুরু' ),
			'chatbot'   => array( 'chatbot', 'bot', 'ai', 'chat', 'assistant', 'gpt', 'openai', 'automation', 'automate', 'n8n', 'zapier' ),
		);
	}

	private static function canon_map() {
		static $map = null;
		if ( null === $map ) {
			$map = array();
			foreach ( self::synonyms() as $key => $words ) {
				foreach ( $words as $w ) {
					if ( ! isset( $map[ $w ] ) ) {
						$map[ $w ] = $key;
					}
				}
			}
		}
		return $map;
	}

	/** Multi-word phrases that carry one meaning ("how much" = price). */
	private static function phrases() {
		return array(
			'how much'      => ' price ',
			'how long'      => ' time ',
			'how many days' => ' time ',
			'how soon'      => ' time ',
			'who owns'      => ' founder ',
			'who is behind' => ' founder ',
			'get started'   => ' start ',
			'money back'    => ' refund ',
			'source code'   => ' code ',
			'real person'   => ' human ',
		);
	}

	/** Map a misspelled word to a known one ("webiste" -> website, "pirce" -> price). */
	private static function fuzzy_canon( $w ) {
		$len = strlen( $w );
		if ( $len < 5 || preg_match( '/[^a-z]/', $w ) ) {
			return null;
		}
		foreach ( self::canon_map() as $word => $key ) {
			$lw = strlen( $word );
			if ( $lw < 5 || abs( $lw - $len ) > 1 || $word[0] !== $w[0] ) {
				continue;
			}
			if ( levenshtein( $w, $word ) <= ( $len >= 6 ? 2 : 1 ) ) {
				return $key;
			}
		}
		return null;
	}

	private static function tokens( $s ) {
		static $stop = array( 'the', 'a', 'an', 'is', 'are', 'am', 'was', 'do', 'does', 'did', 'you', 'your', 'yours', 'i', 'me', 'my', 'we', 'us', 'our', 'to', 'of', 'for', 'and', 'or', 'in', 'on', 'at', 'it', 'its', 'this', 'that', 'can', 'could', 'would', 'will', 'should', 'how', 'what', 'when', 'where', 'which', 'who', 'why', 'with', 'be', 'have', 'has', 'there', 'any', 'please', 'about', 'tell', 'know', 'want', 'need', 'like', 'get', 'much', 'many', 'some', 'just', 'so', 'if', 'hi', 'hello', 'hey', 'thanks', 'ki', 'ke', 'ar', 'ami', 'amar', 'apnar', 'apni', 'apnara', 'tomar', 'na', 'ta', 'te', 'er', 'kore', 'hobe', 'ache', 'jodi', 'kivabe', 'keno', 'ur', 'u', 'r', 'pls', 'plz', 'whats', 'by', 'from', 'vai', 'bhai', 'vaiya', 'bro', 'sir', 'banate', 'banan', 'banaben', 'banaite', 'koren', 'korben', 'korte', 'diben', 'parben', 'chai', 'lagbe', 'den', 'link', 'bd', 'webhut', 'ok', 'okay', 'yes', 'no', 'also', 'only', 'new', 'one', 'it', 'up' );
		$map   = self::canon_map();
		$words = preg_split( '/[^\p{L}\p{N}\p{M}]+/u', strtr( ' ' . mb_strtolower( $s ) . ' ', self::phrases() ), -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( $words as $w ) {
			if ( in_array( $w, $stop, true ) ) {
				continue;
			}
			if ( isset( $map[ $w ] ) ) {
				$out[] = $map[ $w ];
				continue;
			}
			if ( mb_strlen( $w ) < 2 ) {
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
			if ( isset( $map[ $w ] ) ) {
				$out[] = $map[ $w ];
			} else {
				$fz    = self::fuzzy_canon( $w );
				$out[] = $fz ? $fz : $w;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Typo tolerant: "webiste" still matches "website". */
	private static function similar( $a, $b ) {
		if ( $a === $b ) {
			return 1.0;
		}
		$la = strlen( $a );
		$lb = strlen( $b );
		if ( $la < 5 || $lb < 5 || abs( $la - $lb ) > 2 ) {
			return 0.0;
		}
		return levenshtein( $a, $b ) <= ( max( $la, $lb ) >= 7 ? 2 : 1 ) ? 0.8 : 0.0;
	}

	/** Returns the FAQ entries ranked by how well they match the question. */
	public static function rank( $question ) {
		$qt    = self::tokens( $question );
		$items = self::faq_items();
		if ( ! $qt || ! $items ) {
			return array();
		}
		// Rare words count more than words that appear in every entry.
		$docs = array();
		$df   = array();
		foreach ( $items as $i => $item ) {
			$alts = array();
			foreach ( $item['alts'] as $alt ) {
				$alts[] = self::tokens( $alt );
			}
			$qtok       = array_values( array_unique( array_merge( ...$alts ) ) );
			$atok       = self::tokens( $item['a'] );
			$docs[ $i ] = array( $qtok, $atok, $alts );
			foreach ( array_unique( array_merge( $qtok, $atok ) ) as $t ) {
				$df[ $t ] = ( $df[ $t ] ?? 0 ) + 1;
			}
		}
		$n      = count( $items );
		$ranked = array();
		foreach ( $items as $i => $item ) {
			list( $qtok, $atok, $alts ) = $docs[ $i ];
			$got = 0.0;
			$max = 0.0;
			foreach ( $qt as $t ) {
				$idf  = log( 1 + $n / ( 1 + ( $df[ $t ] ?? 0 ) ) ) + 0.5;
				$max += $idf;
				$best = 0.0;
				foreach ( $qtok as $f ) {
					$best = max( $best, self::similar( $t, $f ) );
				}
				foreach ( $atok as $f ) {
					$best = max( $best, 0.45 * self::similar( $t, $f ) );
				}
				$got += $best * $idf;
			}
			// How much of one way of asking is covered by the visitor's words.
			$cover = 0.0;
			foreach ( $alts as $at ) {
				if ( ! $at ) {
					continue;
				}
				$hit = 0;
				foreach ( $at as $f ) {
					foreach ( $qt as $t ) {
						if ( self::similar( $t, $f ) > 0 ) {
							++$hit;
							break;
						}
					}
				}
				$cover = max( $cover, $hit / count( $at ) );
			}
			$score = $max > 0 ? 0.8 * ( $got / $max ) + 0.2 * $cover : 0;
			if ( $score > 0 ) {
				$ranked[] = array( 'score' => $score, 'item' => $item );
			}
		}
		usort(
			$ranked,
			function ( $x, $y ) {
				return $y['score'] <=> $x['score'];
			}
		);
		return $ranked;
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
			return array( 'answer' => 'You have reached the question limit for now. Please try again later or contact us directly.', 'source' => 'limit' );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		// Last few messages, so follow-up questions ("and the price?") make sense.
		$history = array();
		$raw     = $req->get_param( 'history' );
		if ( is_array( $raw ) ) {
			foreach ( array_slice( $raw, -6 ) as $h ) {
				if ( is_array( $h ) && isset( $h['role'], $h['text'] ) ) {
					$history[] = array(
						'role' => 'user' === $h['role'] ? 'user' : 'bot',
						'text' => mb_substr( sanitize_textarea_field( (string) $h['text'] ), 0, 600 ),
					);
				}
			}
		}

		$ranked  = self::rank( $q );
		$related = array();
		foreach ( array_slice( $ranked, 0, 4 ) as $r ) {
			$related[] = $r['item']['q'];
		}

		if ( 'none' !== $o['provider'] && ! empty( $o['api_key'] ) ) {
			$ai = self::ask_ai( $q, $history, $o );
			if ( $ai ) {
				$fallback = false !== mb_stripos( $ai, mb_substr( $o['fallback'], 0, 25 ) );
				return array(
					'answer'  => $ai,
					'source'  => $fallback ? 'fallback' : 'ai',
					'related' => array_slice( $related, 0, 3 ),
				);
			}
		}

		$top = $ranked ? $ranked[0] : null;
		if ( $top && $top['score'] >= 0.42 ) {
			return array(
				'answer'  => $top['item']['a'],
				'source'  => 'faq',
				'related' => array_values( array_diff( array_slice( $related, 0, 4 ), array( $top['item']['q'] ) ) ),
			);
		}
		return array(
			'answer'  => $o['fallback'],
			'source'  => 'fallback',
			'related' => array_slice( $related, 0, 3 ), // "Maybe you meant…"
		);
	}

	private static function ask_ai( $q, $history, $o ) {
		$kb = '';
		foreach ( self::faq_items() as $i ) {
			$kb .= 'Q: ' . implode( ' / ', $i['alts'] ) . "\nA: " . $i['a'] . "\n\n";
		}
		$site   = get_bloginfo( 'name' );
		$system = "You are {$o['name']}, the website assistant for \"{$site}\".\n"
			. "Visitors write casually: short phrases, typos, English, Bangla (বাংলা) or Banglish (Bangla in English letters). Work out what they really mean, then answer.\n"
			. "Rules:\n"
			. "- Use ONLY the knowledge base below. You may combine several entries. Never invent prices, timelines, guarantees, links or facts.\n"
			. "- Reply in the same language and style the visitor used (Banglish in, Banglish out).\n"
			. "- Be warm, clear and short: 1-4 sentences. Use a short '- ' list only when listing options.\n"
			. "- Never mention a 'knowledge base' or 'FAQ'.\n"
			. "- If the answer is not covered, reply with exactly: {$o['fallback']}\n\n"
			. "Knowledge base:\n" . $kb;

		$provider = $o['provider'];
		$model    = '' !== trim( (string) $o['model'] ) ? trim( $o['model'] ) : self::MODELS[ $provider ];

		if ( 'gemini' === $provider ) {
			$contents = array();
			foreach ( $history as $h ) {
				$contents[] = array(
					'role'  => 'user' === $h['role'] ? 'user' : 'model',
					'parts' => array( array( 'text' => $h['text'] ) ),
				);
			}
			$contents[] = array(
				'role'  => 'user',
				'parts' => array( array( 'text' => $q ) ),
			);
			$res = wp_remote_post(
				'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent',
				array(
					'timeout' => 25,
					'headers' => array(
						'x-goog-api-key' => $o['api_key'],
						'Content-Type'   => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
							'contents'          => $contents,
							'generationConfig'  => array(
								'temperature'     => 0.3,
								'maxOutputTokens' => 400,
							),
						)
					),
				)
			);
			if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
				return null; // Falls back to offline matching.
			}
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			$text = '';
			foreach ( $body['candidates'][0]['content']['parts'] ?? array() as $part ) {
				$text .= $part['text'] ?? '';
			}
			return '' !== trim( $text ) ? sanitize_textarea_field( trim( $text ) ) : null;
		}

		// OpenAI and Groq share the same chat-completions format.
		$url      = 'groq' === $provider ? 'https://api.groq.com/openai/v1/chat/completions' : 'https://api.openai.com/v1/chat/completions';
		$messages = array( array( 'role' => 'system', 'content' => $system ) );
		foreach ( $history as $h ) {
			$messages[] = array(
				'role'    => 'user' === $h['role'] ? 'user' : 'assistant',
				'content' => $h['text'],
			);
		}
		$messages[] = array( 'role' => 'user', 'content' => $q );
		$res        = wp_remote_post(
			$url,
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $o['api_key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $model,
						'temperature' => 0.3,
						'max_tokens'  => 400,
						'messages'    => $messages,
					)
				),
			)
		);
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return isset( $body['choices'][0]['message']['content'] ) ? sanitize_textarea_field( trim( $body['choices'][0]['message']['content'] ) ) : null;
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
		$in  = is_array( $in ) ? $in : array();
		$pv  = $in['provider'] ?? 'none';
		return array(
			'enabled'  => empty( $in['enabled'] ) ? 0 : 1,
			'name'     => sanitize_text_field( $in['name'] ?? $d['name'] ),
			'subtitle' => sanitize_text_field( $in['subtitle'] ?? $d['subtitle'] ),
			'welcome'  => sanitize_textarea_field( $in['welcome'] ?? $d['welcome'] ),
			'fallback' => sanitize_text_field( $in['fallback'] ?? $d['fallback'] ),
			'teaser'   => sanitize_text_field( $in['teaser'] ?? '' ),
			'contact'  => esc_url_raw( $in['contact'] ?? '' ),
			'whatsapp' => preg_replace( '/[^0-9]/', '', (string) ( $in['whatsapp'] ?? '' ) ),
			'avatar'   => esc_url_raw( $in['avatar'] ?? '' ),
			'color'    => sanitize_hex_color( $in['color'] ?? '' ) ? sanitize_hex_color( $in['color'] ) : $d['color'],
			'theme'    => in_array( $in['theme'] ?? '', array( 'light', 'dark' ), true ) ? $in['theme'] : 'light',
			'position' => in_array( $in['position'] ?? '', array( 'right', 'left' ), true ) ? $in['position'] : 'right',
			'chips'    => min( 8, absint( $in['chips'] ?? $d['chips'] ) ),
			'footer'   => sanitize_text_field( $in['footer'] ?? '' ),
			'faq'      => sanitize_textarea_field( $in['faq'] ?? '' ),
			'provider' => in_array( $pv, array( 'none', 'gemini', 'groq', 'openai' ), true ) ? $pv : 'none',
			// Keep the saved key when the field is left blank.
			'api_key'  => ( isset( $in['api_key'] ) && '' !== trim( $in['api_key'] ) ) ? sanitize_text_field( $in['api_key'] ) : $old['api_key'],
			'model'    => sanitize_text_field( $in['model'] ?? '' ),
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
		$f = function ( $key ) use ( $n ) {
			return esc_attr( $n . '[' . $key . ']' );
		};
		?>
		<div class="wrap">
			<h1>AI FAQ Chatbot Lite <small style="font-size:12px;color:#777">v<?php echo esc_html( self::VERSION ); ?></small></h1>
			<p>Write your FAQ once. The chat widget answers visitors 24/7. <strong><?php echo (int) $c; ?></strong> FAQ entries detected.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'aifaq' ); ?>
				<h2>Knowledge</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Enable widget</th><td><label><input type="checkbox" name="<?php echo $f( 'enabled' ); ?>" value="1" <?php checked( $o['enabled'] ); ?> /> Show the chat on the site</label></td></tr>
					<tr><th scope="row"><label for="aifaq_faq">FAQ</label></th><td>
						<textarea id="aifaq_faq" class="large-text code" rows="18" name="<?php echo $f( 'faq' ); ?>"><?php echo esc_textarea( $o['faq'] ); ?></textarea>
						<p class="description"><code>Q: question</code> then <code>A: answer</code>, blank line between entries. Add extra <code>Q:</code> lines for other ways people ask the same thing (Bangla / Banglish too). Answers can use several lines, <code>**bold**</code>, <code>- list items</code> and links.</p></td></tr>
					<tr><th scope="row"><label for="aifaq_chips">Suggested questions</label></th><td><input id="aifaq_chips" type="number" min="0" max="8" class="small-text" name="<?php echo $f( 'chips' ); ?>" value="<?php echo esc_attr( $o['chips'] ); ?>" /> <span class="description">How many FAQ questions to show as quick buttons when the chat opens.</span></td></tr>
				</table>

				<h2>AI answers (optional)</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="aifaq_provider">AI provider</label></th><td>
						<select id="aifaq_provider" name="<?php echo $f( 'provider' ); ?>">
							<option value="none" <?php selected( $o['provider'], 'none' ); ?>>None — smart offline matching (free)</option>
							<option value="gemini" <?php selected( $o['provider'], 'gemini' ); ?>>Google Gemini (free tier)</option>
							<option value="groq" <?php selected( $o['provider'], 'groq' ); ?>>Groq (free tier)</option>
							<option value="openai" <?php selected( $o['provider'], 'openai' ); ?>>OpenAI (paid)</option>
						</select>
						<p class="description">Free keys: <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">Google AI Studio</a> · <a href="https://console.groq.com/keys" target="_blank" rel="noopener">Groq Console</a>. OpenAI keys need prepaid credit.</p></td></tr>
					<tr><th scope="row"><label for="aifaq_key">API key</label></th><td>
						<input id="aifaq_key" type="password" class="regular-text" name="<?php echo $f( 'api_key' ); ?>" value="" autocomplete="new-password" placeholder="<?php echo $o['api_key'] ? esc_attr__( '•••••• saved — leave blank to keep', 'ai-faq-chatbot-lite' ) : ''; ?>" />
						<p class="description">Stored on your server, never sent to the browser. If the AI is unavailable the bot falls back to offline matching.</p></td></tr>
					<tr><th scope="row"><label for="aifaq_model">Model</label></th><td><input id="aifaq_model" class="regular-text" name="<?php echo $f( 'model' ); ?>" value="<?php echo esc_attr( $o['model'] ); ?>" placeholder="auto" />
						<p class="description">Leave blank for the default: <?php echo esc_html( implode( ' · ', array_map( function ( $k, $v ) { return $k . ': ' . $v; }, array_keys( self::MODELS ), self::MODELS ) ) ); ?></p></td></tr>
					<tr><th scope="row"><label for="aifaq_limit">Questions per visitor / hour</label></th><td><input id="aifaq_limit" type="number" min="1" class="small-text" name="<?php echo $f( 'limit' ); ?>" value="<?php echo esc_attr( $o['limit'] ); ?>" /></td></tr>
				</table>

				<h2>Look &amp; messages</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="aifaq_name">Bot name</label></th><td><input id="aifaq_name" class="regular-text" name="<?php echo $f( 'name' ); ?>" value="<?php echo esc_attr( $o['name'] ); ?>" /></td></tr>
					<tr><th scope="row"><label for="aifaq_subtitle">Status line</label></th><td><input id="aifaq_subtitle" class="regular-text" name="<?php echo $f( 'subtitle' ); ?>" value="<?php echo esc_attr( $o['subtitle'] ); ?>" /></td></tr>
					<tr><th scope="row"><label for="aifaq_avatar">Avatar image URL</label></th><td><input id="aifaq_avatar" class="regular-text" name="<?php echo $f( 'avatar' ); ?>" value="<?php echo esc_attr( $o['avatar'] ); ?>" placeholder="Uses your Site Icon when empty" /></td></tr>
					<tr><th scope="row"><label for="aifaq_welcome">Welcome message</label></th><td><textarea id="aifaq_welcome" class="large-text" rows="3" name="<?php echo $f( 'welcome' ); ?>"><?php echo esc_textarea( $o['welcome'] ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="aifaq_teaser">Teaser bubble</label></th><td><input id="aifaq_teaser" class="large-text" name="<?php echo $f( 'teaser' ); ?>" value="<?php echo esc_attr( $o['teaser'] ); ?>" />
						<p class="description">Small message that pops up next to the chat button after a few seconds. Leave empty to turn it off.</p></td></tr>
					<tr><th scope="row"><label for="aifaq_fallback">No-answer message</label></th><td><input id="aifaq_fallback" class="large-text" name="<?php echo $f( 'fallback' ); ?>" value="<?php echo esc_attr( $o['fallback'] ); ?>" /></td></tr>
					<tr><th scope="row"><label for="aifaq_whatsapp">WhatsApp number</label></th><td><input id="aifaq_whatsapp" class="regular-text" name="<?php echo $f( 'whatsapp' ); ?>" value="<?php echo esc_attr( $o['whatsapp'] ); ?>" placeholder="8801XXXXXXXXX" />
						<p class="description">With country code, digits only. Shown as a "Chat on WhatsApp" button when the bot can't help.</p></td></tr>
					<tr><th scope="row"><label for="aifaq_contact">Contact page URL</label></th><td><input id="aifaq_contact" class="regular-text" name="<?php echo $f( 'contact' ); ?>" value="<?php echo esc_attr( $o['contact'] ); ?>" placeholder="https://example.com/contact" /></td></tr>
					<tr><th scope="row"><label for="aifaq_color">Brand color</label></th><td><input id="aifaq_color" type="color" name="<?php echo $f( 'color' ); ?>" value="<?php echo esc_attr( $o['color'] ); ?>" /></td></tr>
					<tr><th scope="row">Theme</th><td>
						<label><input type="radio" name="<?php echo $f( 'theme' ); ?>" value="light" <?php checked( $o['theme'], 'light' ); ?> /> Light</label> &nbsp;
						<label><input type="radio" name="<?php echo $f( 'theme' ); ?>" value="dark" <?php checked( $o['theme'], 'dark' ); ?> /> Dark</label></td></tr>
					<tr><th scope="row">Position</th><td>
						<label><input type="radio" name="<?php echo $f( 'position' ); ?>" value="right" <?php checked( $o['position'], 'right' ); ?> /> Bottom right</label> &nbsp;
						<label><input type="radio" name="<?php echo $f( 'position' ); ?>" value="left" <?php checked( $o['position'], 'left' ); ?> /> Bottom left</label></td></tr>
					<tr><th scope="row"><label for="aifaq_footer">Footer text</label></th><td><input id="aifaq_footer" class="regular-text" name="<?php echo $f( 'footer' ); ?>" value="<?php echo esc_attr( $o['footer'] ); ?>" placeholder="e.g. Powered by BD Webhut" /></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ---------------- Front-end widget ---------------- */

	public static function widget() {
		$o      = self::opts();
		$avatar = $o['avatar'] ? $o['avatar'] : get_site_icon_url( 96 );
		$cfg    = array(
			'endpoint' => esc_url_raw( rest_url( self::NS . '/ask' ) ),
			'name'     => $o['name'],
			'subtitle' => $o['subtitle'],
			'welcome'  => $o['welcome'],
			'teaser'   => $o['teaser'],
			'contact'  => $o['contact'],
			'whatsapp' => $o['whatsapp'],
			'avatar'   => $avatar ? esc_url_raw( $avatar ) : '',
			'color'    => $o['color'],
			'theme'    => $o['theme'],
			'position' => $o['position'],
			'footer'   => $o['footer'],
			'chips'    => array_slice( wp_list_pluck( self::faq_items(), 'q' ), 0, (int) $o['chips'] ),
		);
		?>
		<div id="aifaq-root"></div>
		<script>
		window.AIFAQ_CONFIG=<?php echo wp_json_encode( $cfg ); ?>;
		<?php echo self::widget_js(); // phpcs:ignore -- static script, no user input. ?>
		</script>
		<?php
	}

	/** The widget lives in a Shadow DOM, so theme CSS can never break its design. */
	private static function widget_js() {
		return <<<'JS'
(function(){
var C=window.AIFAQ_CONFIG||{},host=document.getElementById('aifaq-root');if(!host||host.shadowRoot)return;
var R=host.attachShadow({mode:'open'});
function hex(h){h=(h||'#2e6bf7').replace('#','');if(h.length===3)h=h.split('').map(function(c){return c+c}).join('');return[parseInt(h.substr(0,2),16),parseInt(h.substr(2,2),16),parseInt(h.substr(4,2),16)]}
function shade(c,p){return'rgb('+c.map(function(v){return Math.round(p<0?v*(1+p):v+(255-v)*p)}).join(',')+')'}
var rgb=hex(C.color),lum=(0.299*rgb[0]+0.587*rgb[1]+0.114*rgb[2])/255,onAcc=lum>0.62?'#0b1220':'#ffffff';
var dark=C.theme==='dark',left=C.position==='left';
var css=`
:host{all:initial}
*{box-sizing:border-box;margin:0;padding:0;font-family:"Inter",ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans Bengali",sans-serif}
.w{--a:${C.color};--a2:${shade(rgb,-0.28)};--on:${onAcc};--bg:${dark?'#0f1115':'#ffffff'};--soft:${dark?'#161a21':'#f6f7f9'};--card:${dark?'#1d222b':'#ffffff'};--line:${dark?'#2a303b':'#e9ebf0'};--tx:${dark?'#eef1f6':'#0f172a'};--mu:${dark?'#9aa4b2':'#64748b'};
 position:fixed;${left?'left':'right'}:24px;bottom:24px;z-index:2147483000;font-size:14px;line-height:1.5;color:var(--tx);-webkit-font-smoothing:antialiased}
button{font:inherit;color:inherit;background:none;border:0;cursor:pointer}
.launch{width:62px;height:62px;border-radius:50%;background:linear-gradient(135deg,var(--a),var(--a2));color:var(--on);display:grid;place-items:center;box-shadow:0 10px 30px -6px rgba(0,0,0,.35),0 0 0 0 var(--a);transition:transform .25s cubic-bezier(.2,.9,.3,1.3),box-shadow .25s;position:relative}
.launch:hover{transform:scale(1.06)}
.launch svg{position:absolute;width:28px;height:28px;transition:transform .3s cubic-bezier(.2,.9,.3,1.2),opacity .2s}
.launch .ic2{opacity:0;transform:rotate(-90deg) scale(.6)}
.open .launch .ic1{opacity:0;transform:rotate(90deg) scale(.6)}
.open .launch .ic2{opacity:1;transform:none}
.badge{position:absolute;top:2px;${left?'left':'right'}:2px;width:14px;height:14px;border-radius:50%;background:#ef4444;border:2.5px solid #fff;transform:scale(0);transition:transform .25s}
.has-unread .badge{transform:scale(1)}
.pulse{position:absolute;inset:0;border-radius:50%;box-shadow:0 0 0 0 var(--a);animation:p 2.4s infinite}
@keyframes p{0%{box-shadow:0 0 0 0 color-mix(in srgb,var(--a) 55%,transparent)}70%{box-shadow:0 0 0 16px transparent}100%{box-shadow:0 0 0 0 transparent}}
.open .pulse,.seen .pulse{display:none}
.teaser{position:absolute;bottom:76px;${left?'left':'right'}:0;width:260px;background:var(--card);color:var(--tx);border:1px solid var(--line);border-radius:16px;padding:14px 34px 14px 14px;box-shadow:0 18px 40px -12px rgba(15,23,42,.35);display:flex;gap:10px;align-items:flex-start;cursor:pointer;opacity:0;transform:translateY(10px) scale(.96);transform-origin:bottom ${left?'left':'right'};pointer-events:none;transition:all .35s cubic-bezier(.2,.9,.3,1.2)}
.teaser.show{opacity:1;transform:none;pointer-events:auto}
.teaser p{font-size:13.5px;line-height:1.45}
.tav{width:32px;height:32px;border-radius:50%;flex:none;background:var(--a) center/cover no-repeat;color:var(--on);display:grid;place-items:center;font-weight:700}
.teaser .tx{position:absolute;top:8px;right:8px;width:22px;height:22px;border-radius:50%;color:var(--mu);display:grid;place-items:center;font-size:14px}
.teaser .tx:hover{background:var(--soft)}
.panel{position:absolute;bottom:80px;${left?'left':'right'}:0;width:384px;height:min(640px,calc(100vh - 120px));background:var(--bg);border-radius:22px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 30px 80px -20px rgba(15,23,42,.45),0 0 0 1px ${dark?'rgba(255,255,255,.06)':'rgba(15,23,42,.06)'};opacity:0;transform:translateY(18px) scale(.94);transform-origin:bottom ${left?'left':'right'};pointer-events:none;transition:opacity .22s ease,transform .32s cubic-bezier(.2,.9,.3,1.15)}
.open .panel{opacity:1;transform:none;pointer-events:auto}
.head{position:relative;padding:18px 16px 18px 18px;background:linear-gradient(135deg,var(--a) 0%,var(--a2) 100%);color:var(--on);display:flex;align-items:center;gap:12px}
.head:after{content:"";position:absolute;inset:0;background:radial-gradient(120% 90% at 100% 0%,rgba(255,255,255,.22),transparent 55%);pointer-events:none}
.av{position:relative;width:44px;height:44px;flex:none}
.av .img{width:100%;height:100%;border-radius:50%;background:rgba(255,255,255,.22) center/cover no-repeat;display:grid;place-items:center;font-weight:700;font-size:17px;border:2px solid rgba(255,255,255,.35)}
.av i{position:absolute;right:0;bottom:1px;width:12px;height:12px;border-radius:50%;background:#22c55e;border:2px solid var(--a2)}
.ht{flex:1;min-width:0}
.ht b{display:block;font-size:16px;font-weight:700;letter-spacing:-.01em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ht span{display:block;font-size:12.5px;opacity:.85}
.hb{width:36px;height:36px;border-radius:12px;display:grid;place-items:center;color:var(--on);transition:background .2s;position:relative;z-index:1}
.hb:hover{background:rgba(255,255,255,.18)}
.hb svg{width:20px;height:20px}
.log{flex:1;overflow-y:auto;padding:18px 16px 8px;background:var(--soft);display:flex;flex-direction:column;gap:4px;scroll-behavior:smooth}
.log::-webkit-scrollbar{width:6px}.log::-webkit-scrollbar-thumb{background:var(--line);border-radius:6px}
.day{align-self:center;font-size:11px;color:var(--mu);background:var(--card);border:1px solid var(--line);padding:3px 10px;border-radius:99px;margin-bottom:10px}
.row{display:flex;gap:8px;align-items:flex-end;animation:in .35s cubic-bezier(.2,.9,.3,1.1) both}
@keyframes in{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.row.me{justify-content:flex-end}
.row .mini{width:28px;height:28px;border-radius:50%;flex:none;background:var(--a) center/cover no-repeat;color:var(--on);display:grid;place-items:center;font-size:12px;font-weight:700}
.row.cont .mini{visibility:hidden}
.bub{max-width:78%;padding:10px 14px;border-radius:18px;word-wrap:break-word;font-size:14px}
.bot .bub{background:var(--card);color:var(--tx);border:1px solid var(--line);border-bottom-left-radius:6px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.me .bub{background:linear-gradient(135deg,var(--a),var(--a2));color:var(--on);border-bottom-right-radius:6px}
.bub a{color:inherit;font-weight:600;text-decoration:underline;text-underline-offset:2px}
.bub ul{margin:6px 0 2px 18px}.bub li{margin:2px 0}
.time{font-size:10.5px;color:var(--mu);margin:2px 0 10px;padding:0 4px}
.time.l{margin-left:36px}.time.r{align-self:flex-end}
.typing .bub{display:flex;gap:4px;padding:14px 16px}
.typing .bub span{width:7px;height:7px;border-radius:50%;background:var(--mu);animation:t 1.2s infinite}
.typing .bub span:nth-child(2){animation-delay:.15s}.typing .bub span:nth-child(3){animation-delay:.3s}
@keyframes t{0%,60%,100%{transform:translateY(0);opacity:.45}30%{transform:translateY(-5px);opacity:1}}
.chips{display:flex;flex-wrap:wrap;gap:7px;margin:4px 0 12px 36px;animation:in .4s .1s both}
.chips button{padding:7px 13px;border-radius:99px;border:1px solid color-mix(in srgb,var(--a) 45%,var(--line));background:var(--card);color:${dark?'var(--tx)':'var(--a2)'};font-size:13px;font-weight:500;transition:all .18s;text-align:left}
.chips button:hover{background:var(--a);border-color:var(--a);color:var(--on);transform:translateY(-1px)}
.chips small{width:100%;font-size:11px;color:var(--mu);font-weight:600;letter-spacing:.02em}
.hand{display:flex;gap:8px;margin:2px 0 12px 36px;flex-wrap:wrap;animation:in .4s both}
.hand a{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:12px;font-size:13px;font-weight:600;text-decoration:none;transition:transform .15s,box-shadow .15s}
.hand a:hover{transform:translateY(-1px);box-shadow:0 6px 16px -6px rgba(0,0,0,.3)}
.hand .wa{background:#25d366;color:#fff}.hand .ct{background:var(--card);color:var(--tx);border:1px solid var(--line)}
.hand svg{width:16px;height:16px}
.foot{background:var(--bg);border-top:1px solid var(--line);padding:12px 12px 8px}
.box{display:flex;align-items:flex-end;gap:8px;background:var(--soft);border:1.5px solid var(--line);border-radius:16px;padding:6px 6px 6px 14px;transition:border-color .2s,box-shadow .2s}
.box:focus-within{border-color:var(--a);box-shadow:0 0 0 4px color-mix(in srgb,var(--a) 16%,transparent)}
textarea{flex:1;border:0;outline:0;background:transparent;resize:none;font:inherit;font-size:14px;color:var(--tx);max-height:110px;padding:7px 0;line-height:1.45}
textarea::placeholder{color:var(--mu)}
.send{width:38px;height:38px;border-radius:12px;background:var(--a);color:var(--on);display:grid;place-items:center;flex:none;transition:transform .15s,opacity .2s}
.send:disabled{opacity:.35;cursor:default}.send:not(:disabled):hover{transform:scale(1.06)}
.send svg{width:18px;height:18px}
.brand{text-align:center;font-size:11px;color:var(--mu);padding-top:7px}
@media (max-width:480px){.w{${left?'left':'right'}:16px;bottom:16px}.panel{position:fixed;inset:0;width:100%;height:100%;border-radius:0}.open .launch,.open .teaser{display:none}}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
`;
var I={
 chat:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8.5 8.5 0 0 1-12.6 7.4L3 21l1.6-5.4A8.5 8.5 0 1 1 21 12z"/><path d="M8.5 12h.01M12 12h.01M15.5 12h.01" stroke-width="2.6"/></svg>',
 down:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>',
 send:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
 reset:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>',
 wa:'<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.3 14.1c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.3-.7-2.8-1.1-4.6-3.9-4.7-4.1-.1-.2-1.1-1.5-1.1-2.9s.7-2 1-2.3c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.3.5-.4.4c-.1.1-.3.3-.1.6.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.3 2.4 1.5.3.1.5.1.6-.1l.9-1.1c.2-.3.4-.2.7-.1l1.9.9c.3.1.5.2.5.3.1.2.1.8-.1 1.4z"/></svg>',
 mail:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>'
};
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
function md(s){var lines=esc(s).split('\n'),out='',list=false;lines.forEach(function(l){var m=l.match(/^\s*[-•*]\s+(.*)$/);if(m){if(!list){out+='<ul>';list=true}out+='<li>'+m[1]+'</li>';return}if(list){out+='</ul>';list=false}out+=l+'<br>'});if(list)out+='</ul>';out=out.replace(/(<br>)+$/,'');
 out=out.replace(/\*\*(.+?)\*\*/g,'<b>$1</b>').replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,'<a href="$2" target="_blank" rel="noopener">$1</a>').replace(/(^|[\s(>])(https?:\/\/[^\s<]+[^\s<.,)])/g,'$1<a href="$2" target="_blank" rel="noopener">$2</a>');return out}
var initial=(C.name||'A').trim().charAt(0).toUpperCase();
function avatarHTML(cls){return C.avatar?'<div class="'+cls+'" style="background-image:url(\''+esc(C.avatar)+'\')"></div>':'<div class="'+cls+'">'+esc(initial)+'</div>'}
R.innerHTML='<style>'+css+'</style><div class="w"><div class="teaser" role="button" tabindex="0">'+avatarHTML('tav')+'<p></p><button class="tx" aria-label="Dismiss">✕</button></div>'
 +'<div class="panel" role="dialog" aria-label="Chat"><div class="head"><div class="av">'+avatarHTML('img')+'<i></i></div><div class="ht"><b></b><span></span></div><button class="hb rs" aria-label="New conversation" title="New conversation">'+I.reset+'</button><button class="hb x" aria-label="Minimize chat">'+I.down+'</button></div>'
 +'<div class="log" aria-live="polite"></div><div class="foot"><form class="box"><textarea rows="1" maxlength="500" placeholder="Write a message…" aria-label="Your message"></textarea><button class="send" type="submit" aria-label="Send" disabled>'+I.send+'</button></form>'+(C.footer?'<div class="brand">'+esc(C.footer)+'</div>':'')+'</div></div>'
 +'<button class="launch" aria-label="Open chat"><span class="pulse"></span>'+I.chat.replace('<svg','<svg class="ic1"')+I.down.replace('<svg','<svg class="ic2"')+'<span class="badge"></span></button></div>';
var $=function(s){return R.querySelector(s)},W=$('.w'),log=$('.log'),ta=$('textarea'),send=$('.send'),teaser=$('.teaser');
$('.ht b').textContent=C.name||'Assistant';$('.ht span').textContent=C.subtitle||'';$('.teaser p').textContent=C.teaser||'';
var KEY='aifaq_chat_v2',st;try{st=JSON.parse(sessionStorage.getItem(KEY))||{}}catch(e){st={}}st.msgs=st.msgs||[];
function save(){try{sessionStorage.setItem(KEY,JSON.stringify(st))}catch(e){}}
function now(){return new Date().toLocaleTimeString([],{hour:'numeric',minute:'2-digit'})}
var lastWho=null;
function scroll(){log.scrollTop=log.scrollHeight}
function bubble(text,who,t,anim){var row=document.createElement('div');row.className='row '+who+(lastWho===who?' cont':'');if(!anim)row.style.animation='none';
 row.innerHTML=(who==='bot'?avatarHTML('mini'):'')+'<div class="bub">'+(who==='bot'?md(text):esc(text).replace(/\n/g,'<br>'))+'</div>';
 var prev=log.lastElementChild;if(prev&&prev.classList.contains('time')&&lastWho===who)prev.remove();
 log.appendChild(row);var tm=document.createElement('div');tm.className='time '+(who==='bot'?'l':'r');tm.textContent=t||now();log.appendChild(tm);lastWho=who;scroll()}
function chips(list,label){if(!list||!list.length)return;var d=document.createElement('div');d.className='chips';if(label)d.innerHTML='<small>'+esc(label)+'</small>';list.forEach(function(q){var b=document.createElement('button');b.type='button';b.textContent=q;b.onclick=function(){d.remove();ask(q)};d.appendChild(b)});log.appendChild(d);scroll()}
function handoff(){if(!C.whatsapp&&!C.contact)return;var d=document.createElement('div');d.className='hand';
 if(C.whatsapp)d.innerHTML+='<a class="wa" target="_blank" rel="noopener" href="https://wa.me/'+encodeURIComponent(C.whatsapp)+'">'+I.wa+'Chat on WhatsApp</a>';
 if(C.contact)d.innerHTML+='<a class="ct" href="'+esc(C.contact)+'">'+I.mail+'Contact us</a>';log.appendChild(d);scroll()}
function clearTransient(){R.querySelectorAll('.chips,.hand').forEach(function(n){n.remove()})}
function render(){log.innerHTML='<div class="day">Today</div>';lastWho=null;st.msgs.forEach(function(m){bubble(m.text,m.who,m.t,false)});if(st.msgs.length<=1)chips(C.chips)}
function start(){if(!st.msgs.length){st.msgs.push({who:'bot',text:C.welcome,t:now()});save()}render()}
var busy=false;
function ask(q){q=(q||'').trim();if(!q||busy)return;busy=true;clearTransient();var t=now();bubble(q,'me',t,true);
 var hist=st.msgs.slice(-6).map(function(m){return{role:m.who==='me'?'user':'bot',text:m.text}});
 st.msgs.push({who:'me',text:q,t:t});save();ta.value='';grow();
 var ty=document.createElement('div');ty.className='row bot typing'+(lastWho==='bot'?' cont':'');ty.innerHTML=avatarHTML('mini')+'<div class="bub"><span></span><span></span><span></span></div>';log.appendChild(ty);scroll();
 var t0=Date.now();
 fetch(C.endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({question:q,history:hist})})
 .then(function(r){return r.json()}).catch(function(){return{answer:'Connection problem. Please try again.',source:'error'}})
 .then(function(j){setTimeout(function(){ty.remove();var a=j.answer||'…',at=now();bubble(a,'bot',at,true);st.msgs.push({who:'bot',text:a,t:at});save();
  if(j.source==='fallback'){handoff();chips(j.related,'Maybe you meant:')}else if(j.related&&j.related.length)chips(j.related.slice(0,3),'Related questions');
  busy=false;if(!W.classList.contains('open'))W.classList.add('has-unread')},Math.max(0,650-(Date.now()-t0)))})}
function grow(){ta.style.height='auto';ta.style.height=Math.min(ta.scrollHeight,110)+'px';send.disabled=!ta.value.trim()}
ta.addEventListener('input',grow);
ta.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();ask(ta.value)}});
$('form').addEventListener('submit',function(e){e.preventDefault();ask(ta.value)});
var started=false;
function toggle(force){var o=typeof force==='boolean'?force:!W.classList.contains('open');W.classList.toggle('open',o);W.classList.remove('has-unread');W.classList.add('seen');teaser.classList.remove('show');
 if(o){if(!started){start();started=true}setTimeout(function(){ta.focus()},250);st.open=1}else st.open=0;save()}
$('.launch').onclick=function(){toggle()};$('.x').onclick=function(){toggle(false)};
$('.rs').onclick=function(){st.msgs=[];save();start()};
teaser.onclick=function(e){if(e.target.closest('.tx')){teaser.classList.remove('show');st.teased=1;save();return}toggle(true)};
R.addEventListener('keydown',function(e){if(e.key==='Escape')toggle(false)});
if(st.open)toggle(true);
else if(C.teaser&&!st.teased){setTimeout(function(){if(!W.classList.contains('open')){teaser.classList.add('show');W.classList.add('has-unread');st.teased=1;save()}},4500)}
})();
JS;
	}
}

add_action( 'plugins_loaded', array( 'AIFAQ_Bot', 'init' ) );
