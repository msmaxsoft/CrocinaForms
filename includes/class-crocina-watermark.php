<?php
/**
 * Image watermarking for uploaded files.
 *
 * Supports text watermark, logo (PNG) overlay, or both combined.
 * Uses either GD or Imagick, whichever is available.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

final class Crocina_Watermark {

	/**
	 * Apply watermark (text, logo, or both) to an image file.
	 *
	 * Overwrites the original file with the watermarked version on success.
	 *
	 * @param string $file_path Absolute path to the uploaded image.
	 * @param array  $settings  Watermark settings array (merged with defaults).
	 * @return bool True on success, false on failure.
	 */
	public function apply( $file_path, array $settings = array() ) {
		if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
			return false;
		}

		$settings = $this->parse_settings( $settings );

		// Only watermark image types.
		$mime = wp_check_filetype( $file_path )['type'] ?? '';
		$allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif' );
		if ( ! in_array( $mime, $allowed_mimes, true ) ) {
			return false;
		}

		$type      = $settings['watermark_type'] ?? 'text';
		$logo_path = null;

		if ( in_array( $type, array( 'logo', 'both' ), true ) ) {
			$logo_path = $this->get_logo_path( $settings );
			if ( ! $logo_path && 'logo' === $type ) {
				return false;
			}
		}

		// Try Imagick first, fall back to GD.
		if ( extension_loaded( 'imagick' ) ) {
			return $this->apply_imagick( $file_path, $settings, $logo_path );
		}

		if ( function_exists( 'imagecreatefromstring' ) ) {
			return $this->apply_gd( $file_path, $settings, $logo_path );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Crocina Watermark: No image library (GD/Imagick) available.' );
		}

		return false;
	}

	/* ------------------------------------------------------------------ */
	/*  Private helpers                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Merge user settings with defaults.
	 *
	 * @param array $settings
	 * @return array
	 */
	private function parse_settings( array $settings ) {
		$defaults = array(
			'watermark_type'         => 'text',
			'watermark_text'         => get_bloginfo( 'name' ) ?: 'Crocina Forms',
			'watermark_position'     => 'bottom-right',
			'watermark_opacity'      => 40,
			'watermark_font_size'    => 24,
			'watermark_color'        => '#ffffff',
			'watermark_logo_id'      => 0,
			'watermark_logo_max_width' => 120,
			'watermark_logo_opacity' => 60,
		);
		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Resolve the path to the uploaded logo file.
	 *
	 * @param array $settings
	 * @return string|null
	 */
	private function get_logo_path( $settings ) {
		$logo_id = absint( $settings['watermark_logo_id'] ?? 0 );
		if ( ! $logo_id ) {
			return null;
		}
		$path = get_attached_file( $logo_id );
		if ( ! $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		// Only accept PNG logos.
		$mime = wp_check_filetype( $path )['type'] ?? '';
		if ( 'image/png' !== $mime ) {
			return null;
		}
		return $path;
	}

	/**
	 * Scale a logo to fit within max_width while preserving aspect ratio.
	 *
	 * @param string $logo_path
	 * @param int    $max_width
	 * @return array{path:string, w:int, h:int, src_w:int, src_h:int}
	 */
	private function prepare_logo_dimensions( $logo_path, $max_width ) {
		$info = getimagesize( $logo_path );
		$src_w = $info ? $info[0] : 0;
		$src_h = $info ? $info[1] : 0;
		if ( ! $src_w || ! $src_h ) {
			return array( 'path' => $logo_path, 'w' => 0, 'h' => 0, 'src_w' => 0, 'src_h' => 0 );
		}
		$max = max( 16, absint( $max_width ) );
		if ( $src_w <= $max ) {
			return array( 'path' => $logo_path, 'w' => $src_w, 'h' => $src_h, 'src_w' => $src_w, 'src_h' => $src_h );
		}
		$ratio = $max / $src_w;
		return array(
			'path'  => $logo_path,
			'w'     => (int) round( $src_w * $ratio ),
			'h'     => (int) round( $src_h * $ratio ),
			'src_w' => $src_w,
			'src_h' => $src_h,
		);
	}

	/**
	 * Get the RGBA components from a hex color + opacity.
	 *
	 * @param string $hex     Hex color (e.g. '#ffffff').
	 * @param int    $opacity 0-100.
	 * @return array{red:int,green:int,blue:int,alpha:int}
	 */
	private function hex_to_rgba( $hex, $opacity ) {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return array(
			'red'   => hexdec( $hex[0] . $hex[1] ),
			'green' => hexdec( $hex[2] . $hex[3] ),
			'blue'  => hexdec( $hex[4] . $hex[5] ),
			'alpha' => round( ( 100 - $opacity ) * 1.27 ),
		);
	}

	/**
	 * Calculate x,y for a given position (baseline origin).
	 *
	 * @param string $position
	 * @param int    $img_w
	 * @param int    $img_h
	 * @param int    $el_w   Element width (text bbox or logo width).
	 * @param int    $el_h   Element height (text bbox or logo height).
	 * @param int    $padding
	 * @return array{x:int, y:int}
	 */
	private function calc_position( $position, $img_w, $img_h, $el_w, $el_h, $padding = 20 ) {
		switch ( $position ) {
			case 'top-left':
				return array( 'x' => $padding, 'y' => $padding + $el_h );
			case 'top-right':
				return array( 'x' => $img_w - $el_w - $padding, 'y' => $padding + $el_h );
			case 'center':
				return array(
					'x' => (int) ( ( $img_w - $el_w ) / 2 ),
					'y' => (int) ( ( $img_h + $el_h ) / 2 ),
				);
			case 'bottom-left':
				return array( 'x' => $padding, 'y' => $img_h - $padding );
			case 'bottom-right':
			default:
				return array( 'x' => $img_w - $el_w - $padding, 'y' => $img_h - $padding );
		}
	}

	/**
	 * Top-left origin variant (for imagestring).
	 */
	private function calc_position_top_left( $position, $img_w, $img_h, $el_w, $el_h, $padding = 20 ) {
		$pos = $this->calc_position( $position, $img_w, $img_h, $el_w, $el_h, $padding );
		$pos['y'] = $pos['y'] - $el_h;
		return $pos;
	}

	/* ------------------------------------------------------------------ */
	/*  RTL text helpers                                                  */
	/* ------------------------------------------------------------------ */



	/**
	 * Render RTL (Persian/Arabic) text on a GD or Imagick canvas.
	 *
	 * Simple character reversal breaks Persian character connections
	 * (isolated vs initial/medial/final forms). For proper visual
	 * output we need a shaping library like Ar-PHP.
	 *
	 * Since we cannot bundle such a library, we use a best-effort
	 * approach:
	 *   - Pass the text as-is (logical order). Modern fonts with
	 *     OpenType shaping may handle this correctly.
	 *   - If a shaping library is available via Composer (autoloaded
	 *     in the project), we use it for proper glyph shaping.
	 *
	 * @param string $text The original (logical-order) text.
	 * @param bool   $visual_layout  If true, returns shaped text
	 *               suitable for left-to-right canvas rendering.
	 *               False returns logical-order text.
	 * @return string
	 */
	private function render_rtl_text( $text, $visual_layout = false ) {
		if ( '' === $text ) {
			return $text;
		}

		/*
		 * Try to use Ar-PHP (Khaled Al-Shamaa's Arabic PHP library)
		 * if it is available via Composer autoloading.
		 * https://github.com/khaled-alshamaa/ar-php
		 *
		 * Namespace: ArPHP\I18N\Arabic
		 * Method:    $arabic->utf8Glyphs( $text, 180 )
		 * Returns text in visual order (left-to-right on canvas),
		 * suitable for direct rendering via GD/Imagick.
		 */
		if ( class_exists( 'ArPHP\\I18N\\Arabic' ) ) {
			try {
				$arabic = new \ArPHP\I18N\Arabic();
				$text   = $arabic->utf8Glyphs( $text, 180 );
				return $text;
			} catch ( \Exception $e ) {
				/* Fall through to simple-approach below. */
			}
		}

		/*
		 * Without a shaping library: return the text as-is.
		 * Characters will appear in logical order on the canvas
		 * (they may read right-to-left but characters will be
		 * properly connected if the font supports OpenType shaping).
		 */
		return $text;
	}

	/* ------------------------------------------------------------------ */
	/*  GD implementation                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * @param string      $file_path
	 * @param array       $settings
	 * @param string|null $logo_path
	 * @return bool
	 */
	private function apply_gd( $file_path, array $settings, $logo_path = null ) {
		$img = $this->gd_create_from( $file_path );
		if ( ! $img ) {
			return false;
		}

		$img_w = imagesx( $img );
		$img_h = imagesy( $img );

		if ( $img_w < 100 || $img_h < 100 ) {
			imagedestroy( $img );
			return false;
		}

		$type = $settings['watermark_type'] ?? 'text';

		// Apply logo overlay.
		if ( $logo_path && in_array( $type, array( 'logo', 'both' ), true ) ) {
			$this->overlay_logo_gd( $img, $img_w, $img_h, $settings, $logo_path );
		}

		// Apply text.
		if ( in_array( $type, array( 'text', 'both' ), true ) ) {
			$this->apply_text_gd( $img, $img_w, $img_h, $settings );
		}

		$result = $this->gd_save( $img, $file_path );
		imagedestroy( $img );
		return $result;
	}

	/**
	 * Render text on a GD image.
	 *
	 * Reverses RTL (Persian/Arabic) text before rendering because
	 * GD places glyphs left-to-right on the canvas.
	 */
	private function apply_text_gd( $img, $img_w, $img_h, array $settings ) {
		$rgba      = $this->hex_to_rgba( $settings['watermark_color'], $settings['watermark_opacity'] );
		$color     = imagecolorallocatealpha( $img, $rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha'] );
		$font      = $this->get_font_path();
		$font_size = max( 8, min( 128, (int) $settings['watermark_font_size'] ) );
		$text      = $settings['watermark_text'];

		// Use the RTL-safe text for canvas rendering.
		$text = $this->render_rtl_text( $text, true );

		if ( $font ) {
			$bbox = imagettfbbox( $font_size, 0, $font, $text );
			$tw   = abs( $bbox[4] - $bbox[0] );
			$th   = abs( $bbox[1] - $bbox[7] );
			$pos  = $this->calc_position( $settings['watermark_position'], $img_w, $img_h, $tw, $th );
			imagettftext( $img, $font_size, 0, $pos['x'], $pos['y'], $color, $font, $text );
		} else {
			$built_in = 5;
			$tw       = imagefontwidth( $built_in ) * strlen( $text );
			$th       = imagefontheight( $built_in );
			$pos      = $this->calc_position_top_left( $settings['watermark_position'], $img_w, $img_h, $tw, $th );
			imagestring( $img, $built_in, $pos['x'], $pos['y'], $text, $color );
		}
	}

	/**
	 * Overlay a PNG logo onto a GD image with transparency.
	 */
	private function overlay_logo_gd( $img, $img_w, $img_h, array $settings, $logo_path ) {
		$dims = $this->prepare_logo_dimensions( $logo_path, $settings['watermark_logo_max_width'] );
		if ( $dims['w'] < 1 || $dims['h'] < 1 ) {
			return;
		}

		$logo_src = imagecreatefrompng( $dims['path'] );
		if ( ! $logo_src ) {
			return;
		}

		// Scale the logo if needed.
		if ( $dims['w'] !== $dims['src_w'] || $dims['h'] !== $dims['src_h'] ) {
			$logo_scaled = imagecreatetruecolor( $dims['w'], $dims['h'] );
			imagealphablending( $logo_scaled, false );
			imagesavealpha( $logo_scaled, true );
			$trans = imagecolorallocatealpha( $logo_scaled, 0, 0, 0, 127 );
			imagefill( $logo_scaled, 0, 0, $trans );
			imagecopyresampled( $logo_scaled, $logo_src, 0, 0, 0, 0, $dims['w'], $dims['h'], $dims['src_w'], $dims['src_h'] );
			imagedestroy( $logo_src );
			$logo_src = $logo_scaled;
		}

		// Apply opacity to the logo.
		$logo_opacity = max( 0, min( 100, (int) ( $settings['watermark_logo_opacity'] ?? 60 ) ) );
		if ( $logo_opacity < 100 ) {
			imagefilter( $logo_src, IMG_FILTER_COLORIZE, 0, 0, 0, (int) round( ( 100 - $logo_opacity ) * 1.27 ) );
		}

		$pos = $this->calc_position_top_left( $settings['watermark_position'], $img_w, $img_h, $dims['w'], $dims['h'] );

		imagealphablending( $img, true );
		imagecopy( $img, $logo_src, $pos['x'], $pos['y'], 0, 0, $dims['w'], $dims['h'] );

		imagedestroy( $logo_src );
	}

	/* ------------------------------------------------------------------ */
	/*  GD helpers                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * @param string $file_path
	 * @return resource|\GdImage|false
	 */
	private function gd_create_from( $file_path ) {
		$info = getimagesize( $file_path );
		if ( ! $info ) {
			return false;
		}
		switch ( $info[2] ) {
			case IMAGETYPE_JPEG:
				return imagecreatefromjpeg( $file_path );
			case IMAGETYPE_PNG:
				return imagecreatefrompng( $file_path );
			case IMAGETYPE_GIF:
				return imagecreatefromgif( $file_path );
			default:
				return false;
		}
	}

	/**
	 * @param resource|\GdImage $img
	 * @param string            $file_path
	 * @return bool
	 */
	private function gd_save( $img, $file_path ) {
		$info = getimagesize( $file_path );
		if ( ! $info ) {
			return false;
		}
		switch ( $info[2] ) {
			case IMAGETYPE_JPEG:
				return imagejpeg( $img, $file_path, 90 );
			case IMAGETYPE_PNG:
				imagealphablending( $img, false );
				imagesavealpha( $img, true );
				return imagepng( $img, $file_path, 6 );
			case IMAGETYPE_GIF:
				return imagegif( $img, $file_path );
			default:
				return false;
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Imagick implementation                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * @param string      $file_path
	 * @param array       $settings
	 * @param string|null $logo_path
	 * @return bool
	 */
	private function apply_imagick( $file_path, array $settings, $logo_path = null ) {
		try {
			$image = new Imagick( $file_path );
		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Crocina Watermark (Imagick): ' . $e->getMessage() );
			}
			return false;
		}

		$img_w = $image->getImageWidth();
		$img_h = $image->getImageHeight();

		if ( $img_w < 100 || $img_h < 100 ) {
			$image->destroy();
			return false;
		}

		$type = $settings['watermark_type'] ?? 'text';

		try {
			// Apply logo overlay.
			if ( $logo_path && in_array( $type, array( 'logo', 'both' ), true ) ) {
				$this->overlay_logo_imagick( $image, $img_w, $img_h, $settings, $logo_path );
			}

			// Apply text.
			if ( in_array( $type, array( 'text', 'both' ), true ) ) {
				$this->apply_text_imagick( $image, $img_w, $img_h, $settings );
			}

			$image->writeImage( $file_path );
			$image->destroy();
			return true;
		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Crocina Watermark (Imagick): ' . $e->getMessage() );
			}
			$image->destroy();
			return false;
		}
	}

	/**
	 * Render text via Imagick.
	 *
	 * Reverses RTL (Persian/Arabic) text before rendering because
	 * Imagick annotation also places glyphs left-to-right.
	 */
	private function apply_text_imagick( $image, $img_w, $img_h, array $settings ) {
		$font_size = max( 8, min( 128, (int) $settings['watermark_font_size'] ) );
		$text      = $settings['watermark_text'];
		$opacity   = max( 0, min( 100, (int) $settings['watermark_opacity'] ) );
		$color     = $settings['watermark_color'];
		$font      = $this->get_font_path();

		// Use the RTL-safe text for canvas rendering.
		$text = $this->render_rtl_text( $text, true );

		$draw = new ImagickDraw();
		if ( $font ) {
			$draw->setFont( $font );
		}
		$draw->setFontSize( $font_size );
		$draw->setFillColor( new ImagickPixel( $color ) );
		$draw->setFillOpacity( $opacity / 100 );

		$metrics = $image->queryFontMetrics( $draw, $text );
		$tw      = (int) $metrics['textWidth'];
		$th      = (int) $metrics['textHeight'];
		$pos     = $this->calc_position( $settings['watermark_position'], $img_w, $img_h, $tw, $th );

		$draw->annotation( $pos['x'], $pos['y'], $text );
		$image->drawImage( $draw );
	}

	/**
	 * Overlay a PNG logo via Imagick.
	 */
	private function overlay_logo_imagick( $image, $img_w, $img_h, array $settings, $logo_path ) {
		$dims = $this->prepare_logo_dimensions( $logo_path, $settings['watermark_logo_max_width'] );
		if ( $dims['w'] < 1 || $dims['h'] < 1 ) {
			return;
		}

		try {
			$logo = new Imagick( $dims['path'] );

			if ( $dims['w'] !== $dims['src_w'] || $dims['h'] !== $dims['src_h'] ) {
				$logo->resizeImage( $dims['w'], $dims['h'], Imagick::FILTER_LANCZOS, 1 );
			}

			$logo_opacity = max( 0, min( 100, (int) ( $settings['watermark_logo_opacity'] ?? 60 ) ) );
			if ( $logo_opacity < 100 ) {
				$logo->evaluateImage( Imagick::EVALUATE_MULTIPLY, $logo_opacity / 100, Imagick::CHANNEL_ALPHA );
			}

			$pos = $this->calc_position_top_left( $settings['watermark_position'], $img_w, $img_h, $dims['w'], $dims['h'] );
			$image->compositeImage( $logo, Imagick::COMPOSITE_OVER, $pos['x'], $pos['y'] );

			$logo->destroy();
		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Crocina Watermark (Imagick logo): ' . $e->getMessage() );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Preview image                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Generate a sample watermarked image and output it directly as PNG.
	 *
	 * If a custom sample image is provided via the 'sample_image_path' key in
	 * settings, it is used as the background instead of the default gradient.
	 *
	 * @param array $settings Watermark settings (merged with defaults).
	 * @return void Outputs the PNG directly and exits.
	 */
	public function output_preview_png( array $settings = array() ) {
		$settings = $this->parse_settings( $settings );

		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return;
		}

		/*
		 * Check for a custom sample image path. This is passed by the AJAX
		 * preview handler when the user has uploaded a sample image.
		 */
		$w = 480;
		$h = 320;
		$sample_path = ! empty( $settings['sample_image_path'] ) ? $settings['sample_image_path'] : null;

		if ( $sample_path && is_file( $sample_path ) && is_readable( $sample_path ) ) {
			$img = $this->gd_create_from( $sample_path );
			if ( $img ) {
				// Use the actual dimensions of the loaded sample image.
				$w = imagesx( $img );
				$h = imagesy( $img );
			} else {
				$img = $this->preview_create_default_canvas( $w, $h );
			}
		} else {
			$img = $this->preview_create_default_canvas( $w, $h );
		}

		// Resolve logo path for preview.
		$type      = $settings['watermark_type'] ?? 'text';
		$logo_path = null;
		if ( in_array( $type, array( 'logo', 'both' ), true ) ) {
			$logo_path = $this->get_logo_path( $settings );
		}

		// Apply logo overlay to preview.
		if ( $logo_path && in_array( $type, array( 'logo', 'both' ), true ) ) {
			$this->overlay_logo_gd( $img, $w, $h, $settings, $logo_path );
		}

		// Apply text to preview.
		if ( in_array( $type, array( 'text', 'both' ), true ) ) {
			$this->preview_apply_text( $img, $w, $h, $settings );
		}

		imagepng( $img );
		imagedestroy( $img );
	}

	/**
	 * Create the default preview canvas with diagonal gradient + geometric shapes.
	 *
	 * @param int $w Canvas width.
	 * @param int $h Canvas height.
	 * @return resource|\GdImage
	 */
	private function preview_create_default_canvas( $w, $h ) {
		$img = imagecreatetruecolor( $w, $h );
		$this->preview_draw_gradient( $img, $w, $h );

		$gray = imagecolorallocate( $img, 80, 90, 110 );
		imagefilledrectangle( $img, 40, 180, 120, 260, $gray );
		imagefilledellipse( $img, 360, 80, 70, 50, $gray );
		imagefilledellipse( $img, 80, 80, 60, 60, $gray );

		return $img;
	}

	/**
	 * Fill the preview canvas with a diagonal gradient.
	 */
	private function preview_draw_gradient( $img, $w, $h ) {
		$steps = max( $w, $h );
		for ( $i = 0; $i < $steps; $i++ ) {
			$ratio = $i / $steps;
			$r = (int) ( 70 + $ratio * 60 );
			$g = (int) ( 90 + $ratio * 50 );
			$b = (int) ( 130 + $ratio * 40 );
			$color = imagecolorallocate( $img, $r, $g, $b );
			imagesetpixel( $img, min( $i, $w - 1 ), min( $i, $h - 1 ), $color );
		}
	}

	/**
	 * Apply text watermark to the preview image.
	 *
	 * Reverses RTL (Persian/Arabic) text before rendering.
	 */
	private function preview_apply_text( $img, $w, $h, array $settings ) {
		$rgba      = $this->hex_to_rgba( $settings['watermark_color'], $settings['watermark_opacity'] );
		$color     = imagecolorallocatealpha( $img, $rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha'] );
		$font      = $this->get_font_path();
		$font_size = max( 8, min( 128, (int) $settings['watermark_font_size'] ) );
		$text      = $settings['watermark_text'];

		// Use the RTL-safe text for canvas rendering.
		$text = $this->render_rtl_text( $text, true );

		if ( $font ) {
			$bbox = imagettfbbox( $font_size, 0, $font, $text );
			$tw   = abs( $bbox[4] - $bbox[0] );
			$th   = abs( $bbox[1] - $bbox[7] );
			$pos  = $this->calc_position( $settings['watermark_position'], $w, $h, $tw, $th, 24 );
			imagettftext( $img, $font_size, 0, $pos['x'], $pos['y'], $color, $font, $text );
		} else {
			$built_in = 5;
			$tw       = imagefontwidth( $built_in ) * strlen( $text );
			$th       = imagefontheight( $built_in );
			$pos      = $this->calc_position_top_left( $settings['watermark_position'], $w, $h, $tw, $th, 24 );
			imagestring( $img, $built_in, $pos['x'], $pos['y'], $text, $color );
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Font resolution                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Locate a suitable TrueType font for watermark text.
	 *
	 * @return string|null
	 */
	private function get_font_path() {
		$custom = apply_filters( 'crocina_watermark_font', '' );
		if ( $custom && is_file( $custom ) && is_readable( $custom ) ) {
			return $custom;
		}

		$system_fonts = array(
			'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
			'/usr/share/fonts/TTF/DejaVuSans.ttf',
			'/usr/share/fonts/dejavu/DejaVuSans.ttf',
			'C:\\Windows\\Fonts\\arial.ttf',
			'C:\\Windows\\Fonts\\tahoma.ttf',
			'C:\\Windows\\Fonts\\segoeui.ttf',
			'/System/Library/Fonts/Helvetica.ttc',
			'/Library/Fonts/Arial.ttf',
		);

		foreach ( $system_fonts as $path ) {
			if ( is_file( $path ) && is_readable( $path ) ) {
				return $path;
			}
		}

		return null;
	}
}
