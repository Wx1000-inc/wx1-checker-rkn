<?php
/**
 * Core site-checking logic.
 *
 * Fetches the target domain (main page + a limited number of internal pages)
 * and inspects the combined HTML for each indicator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WXRKN_Checker {

	/** @var int HTTP request timeout in seconds. */
	private $timeout = 15;

	/** @var int How many pages per site to inspect (main page counts as 1). */
	private $max_pages = 3;

	/** @var string User-Agent header sent with every request. */
	private $user_agent = 'Mozilla/5.0 (compatible; WX1RKNChecker/1.0; +https://github.com/Wx1000-inc/wx1-checker-rkn)';

	/** @var string[] HTTP fetch errors keyed by URL (populated during fetch_pages). */
	private $fetch_errors = array();

	// -------------------------------------------------------------------------

	/**
	 * @param array $settings {
	 *     Optional overrides.
	 *     @type int $timeout   HTTP timeout in seconds.
	 *     @type int $max_pages Max pages to fetch per domain.
	 * }
	 */
	public function __construct( array $settings = array() ) {
		if ( isset( $settings['timeout'] ) ) {
			$this->timeout = max( 5, (int) $settings['timeout'] );
		}
		if ( isset( $settings['max_pages'] ) ) {
			$this->max_pages = max( 1, min( 10, (int) $settings['max_pages'] ) );
		}
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Check a single domain and return an associative result array.
	 *
	 * @param  string $domain Raw user-supplied domain or URL.
	 * @return array  Keys: google_analytics, privacy_policy, yandex_metrika,
	 *                       sape, liveinternet, comments (bool each).
	 *                On failure the array contains a single 'error' key.
	 */
	public function check_domain( $domain ) {
		$this->fetch_errors = array();

		$url = $this->normalize_url( trim( $domain ) );

		if ( empty( $url ) ) {
			return array( 'error' => 'Invalid or empty domain.' );
		}

		$pages = $this->fetch_pages( $url );

		if ( empty( $pages ) ) {
			$details = array();
			foreach ( $this->fetch_errors as $tried_url => $reason ) {
				$details[] = $tried_url . ' → ' . $reason;
			}
			$message = 'Could not fetch site';
			if ( ! empty( $details ) ) {
				$message .= ': ' . implode( '; ', $details );
			}
			return array( 'error' => $message );
		}

		$combined = implode( "\n", $pages );

		return array(
			'google_analytics' => $this->check_google_analytics( $combined ),
			'privacy_policy'   => $this->check_privacy_policy( $combined ),
			'yandex_metrika'   => $this->check_yandex_metrika( $combined ),
			'sape'             => $this->check_sape( $combined ),
			'liveinternet'     => $this->check_liveinternet( $combined ),
			'comments'         => $this->check_comments( $combined ),
		);
	}

	// -------------------------------------------------------------------------
	// URL / crawl helpers
	// -------------------------------------------------------------------------

	/**
	 * Ensure the domain has an https:// (or http://) scheme.
	 */
	private function normalize_url( $domain ) {
		if ( empty( $domain ) ) {
			return '';
		}

		// Already has a scheme.
		if ( preg_match( '/^https?:\/\//i', $domain ) ) {
			return rtrim( $domain, '/' );
		}

		return 'https://' . ltrim( $domain, '/' );
	}

	/**
	 * Fetch the main page and up to ($max_pages - 1) internal pages.
	 *
	 * @param  string $base_url
	 * @return string[] Array of HTML bodies; empty if main page could not be loaded.
	 */
	private function fetch_pages( $base_url ) {
		$pages = array();

		// Try HTTPS first, fall back to HTTP.
		$main_html = $this->fetch_url( $base_url );
		if ( false === $main_html && preg_match( '/^https:/i', $base_url ) ) {
			$http_url  = preg_replace( '/^https:/i', 'http:', $base_url );
			$main_html = $this->fetch_url( $http_url );
			if ( false !== $main_html ) {
				$base_url = $http_url;
			}
		}

		if ( false === $main_html ) {
			return array();
		}

		$pages[] = $main_html;

		if ( $this->max_pages > 1 ) {
			$links = $this->extract_internal_links( $main_html, $base_url );
			$links = array_slice( $links, 0, $this->max_pages - 1 );

			foreach ( $links as $link ) {
				$html = $this->fetch_url( $link );
				if ( false !== $html ) {
					$pages[] = $html;
				}
			}
		}

		return $pages;
	}

	/**
	 * Execute a single HTTP GET via wp_remote_get.
	 *
	 * @param  string $url
	 * @return string|false Response body on success, false on failure.
	 */
	private function fetch_url( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $this->timeout,
				'redirection' => 5,
				'user-agent'  => $this->user_agent,
				/**
				 * Filter whether to verify SSL certificates when fetching remote sites.
				 * Defaults to false because many checked sites have self-signed or expired
				 * certificates. Override via:
				 *   add_filter( 'wxrkn_sslverify', '__return_true' );
				 */
				'sslverify'   => (bool) apply_filters( 'wxrkn_sslverify', false ),
				'headers'     => array(
					'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'ru,en-US;q=0.7,en;q=0.3',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->fetch_errors[ $url ] = $response->get_error_message();
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			$this->fetch_errors[ $url ] = 'HTTP ' . $code;
			return false;
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Parse anchor hrefs from HTML and return same-domain absolute URLs,
	 * de-duplicated and filtered to HTML-like paths only.
	 *
	 * @param  string $html
	 * @param  string $base_url
	 * @return string[]
	 */
	private function extract_internal_links( $html, $base_url ) {
		$parsed = wp_parse_url( $base_url );
		if ( ! isset( $parsed['host'] ) ) {
			return array();
		}

		$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'https';
		$host   = $parsed['host'];
		$base   = $scheme . '://' . $host;

		preg_match_all( '/<a[^>]+href=["\']([^"\'#][^"\']*)["\'][^>]*>/i', $html, $matches );

		$links = array();
		$seen  = array();

		foreach ( $matches[1] as $href ) {
			$href = trim( $href );

			// Skip non-HTTP protocols.
			if ( preg_match( '/^(mailto:|tel:|javascript:|#)/i', $href ) ) {
				continue;
			}

			// Resolve to absolute URL.
			if ( strpos( $href, '//' ) === 0 ) {
				$href = $scheme . ':' . $href;
			} elseif ( strpos( $href, '/' ) === 0 ) {
				$href = $base . $href;
			} elseif ( ! preg_match( '/^https?:\/\//i', $href ) ) {
				continue;
			}

			$lp = wp_parse_url( $href );
			if ( ! isset( $lp['host'] ) || $lp['host'] !== $host ) {
				continue;
			}

			$path  = isset( $lp['path'] ) ? $lp['path'] : '/';
			$clean = $base . $path;

			// Skip binary/static assets.
			if ( preg_match( '/\.(jpg|jpeg|png|gif|svg|webp|ico|pdf|zip|rar|doc|docx|xls|xlsx|css|js|xml|txt|mp3|mp4|woff|ttf)$/i', $path ) ) {
				continue;
			}

			// Skip the base URL itself.
			if ( rtrim( $clean, '/' ) === rtrim( $base, '/' ) ) {
				continue;
			}

			if ( isset( $seen[ $clean ] ) ) {
				continue;
			}

			$seen[ $clean ] = true;
			$links[]        = $href;

			if ( count( $links ) >= 15 ) {
				break;
			}
		}

		return $links;
	}

	// -------------------------------------------------------------------------
	// Individual indicator checks
	// -------------------------------------------------------------------------

	/**
	 * Google Analytics (Universal Analytics + GA4 + GTM).
	 */
	private function check_google_analytics( $html ) {
		return $this->match_any(
			$html,
			array(
				'google-analytics\.com/(analytics|ga)\.js',
				'googletagmanager\.com/gtag/js',
				"gtag\s*\(\s*['\"]config['\"]",
				"ga\s*\(\s*['\"]create['\"]",
				"ga\s*\(\s*['\"]send['\"]",
				'_gaq\s*\.\s*push',
				'[\'"](?:UA-\d{4,}-\d+)[\'"]',
				'[\'"](?:G-[A-Z0-9]{7,})[\'"]',
				'google-analytics\.com/collect',
			)
		);
	}

	/**
	 * Privacy policy: look for links/hrefs that reference a privacy page.
	 */
	private function check_privacy_policy( $html ) {
		// Check href attributes for common privacy-policy URL patterns.
		$href_patterns = array(
			'privacy[_\-]?policy',
			'privacy',
			'confidential',
			'%D0%BF%D0%BE%D0%BB%D0%B8%D1%82%D0%B8%D0%BA',  // URL-encoded «политик»
			'конфиденциальност',
			'политик',
		);
		if ( preg_match_all( '/href=["\']([^"\']+)["\']/', $html, $m ) ) {
			foreach ( $m[1] as $href ) {
				foreach ( $href_patterns as $p ) {
					if ( preg_match( '#' . $p . '#iu', $href ) ) {
						return true;
					}
				}
			}
		}

		// Check visible link/heading text.
		$text_patterns = array(
			'политик[аиу].{0,40}конфиденциальност',
			'конфиденциальност',
			'privacy\s+policy',
			'privacy\s+notice',
		);
		return $this->match_any( $html, $text_patterns );
	}

	/**
	 * Yandex Metrika counter.
	 */
	private function check_yandex_metrika( $html ) {
		return $this->match_any(
			$html,
			array(
				'mc\.yandex\.ru/metrika',
				'metrika\.yandex\.ru',
				'yandex\.ru/metrika',
				'ym\s*\(',
				'Ya\.Metrika',
				'yandex_metrika',
				'YandexMetrika',
			)
		);
	}

	/**
	 * Sape link-exchange counter (detected via acint.net code or legacy sape.ru markers).
	 */
	private function check_sape( $html ) {
		return $this->match_any(
			$html,
			array(
				'acint\.net',
				'sape\.ru',
				'__sape_',
				'sape_block',
				'sape\.js',
			)
		);
	}

	/**
	 * LiveInternet / Rambler counter (counter.yadro.ru).
	 */
	private function check_liveinternet( $html ) {
		return $this->match_any(
			$html,
			array(
				'counter\.yadro\.ru',
				'cnt\.yadro\.ru',
				'liveinternet\.ru/click',
				'liveinternet\.ru/counter',
				'liveinternet\.ru/visit',
			)
		);
	}

	/**
	 * Comments section (WordPress, Disqus, etc.).
	 */
	private function check_comments( $html ) {
		return $this->match_any(
			$html,
			array(
				'id=["\']comments["\']',
				'class=["\'][^"\']*comments[_\-]area[^"\']*["\']',
				'class=["\'][^"\']*comment[_\-]list[^"\']*["\']',
				'class=["\'][^"\']*wp[_\-]comment[^"\']*["\']',
				'id=["\']respond["\']',
				'disqus_thread',
				'class=["\'][^"\']*disqus[^"\']*["\']',
				'comment_form',
				'<section[^>]+class=["\'][^"\']*comments?[^"\']*["\']',
			)
		);
	}

	// -------------------------------------------------------------------------
	// Utility
	// -------------------------------------------------------------------------

	/**
	 * Return true if any pattern matches the haystack (case-insensitive, unicode).
	 *
	 * @param  string   $haystack
	 * @param  string[] $patterns  PCRE patterns without delimiters.
	 * @return bool
	 */
	private function match_any( $haystack, array $patterns ) {
		foreach ( $patterns as $pattern ) {
			if ( preg_match( '#' . $pattern . '#iu', $haystack ) ) {
				return true;
			}
		}
		return false;
	}
}
