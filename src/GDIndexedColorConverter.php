<?php
/**
 * GDIndexedColorConverter
 *
 * A converter to convert an image resource into indexed color mode.
 *
 * Licensed under The MIT License
 *
 * @author Jeremy Yu
 * @copyright Copyright 2014 Jeremy Yu
 * @license https://github.com/ccpalettes/gd-indexed-color-converter/blob/master/LICENSE
 **/

require_once('GDQuantNode.php');

/**
 * Index Color Mode Converter Class
 *
 * Convert an image to indexed color mode.
 */
class GDIndexedColorConverter
{
	/**
	 * A color lookup cache to speed up our naive nearest-neighbor search.
	 */
	protected $lookupCache = [];

	/**
	 * Number of cache lookups.
	 */
	protected $cacheLookups = 0;

	/**
	 * Number of cache hits.
	 */
	protected $cacheHits = 0;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
	}

	/**
	 *
	 * Convert an image resource to indexed color mode.
	 * The original image resource will not be changed, a new image resource will be created.
	 *
	 * @param ImageResource $im The image resource
	 * @param array $palette The color palette
	 * @param float $dither The Floyd–Steinberg dither amount, value is between 0 and 1 and default value is 0.75
	 * @return ImageResource The image resource in indexed colr mode.
	 */
	public function convertToIndexedColor ($im, $palette, $dither = 0.75)
	{
		$newPalette = array();
		foreach($palette as $paletteColor) {
			$newPalette[] = array(
				'rgb' => $this->checkAlpha($paletteColor),
				'lab' => $this->RGBtoLab($paletteColor),
			);
		}

		$width = imagesx($im);
		$height = imagesy($im);

		$newImage = $this->floydSteinbergDither($im, $width, $height, $newPalette, $dither);

		return $newImage;
	}

	/**
	 * Very rudimentary palette selector, consider providing a shrunken copy of your source if it's large.
	 *
	 * @param ImageResource $im Image to create the palette.
	 * @param int $colors Number of target colors.
	 * @param int $significantbits Number of significant bits. Lower numbers = faster. Default 5
	 * @return array Array of selected colors.
	 */
	public function quantize($im, $colors, $significantbits = 5) {
		$width = imagesx($im);
		$height = imagesy($im);
		$quant = new GDQuantNode($significantbits);
		for($x = 0; $x < $width; $x++) {
			for($y = 0; $y < $height; $y++) {
				$ind = imagecolorat($im, $x, $y);
				if(($ind >> 24) > 110) {
					$ind = (127 << 24);
				}
				$r = (($ind >> 16) & 0xFF);
				$g = (($ind >> 8) & 0xFF);
				$b = ($ind & 0xFF);
				$a = ($ind >> 24);
				$quant->add($r, $g, $b, $a);
			}
		}
		$leaves = $quant->findLeaves();
		if(count($leaves) > $colors) {
			//Least frequent first.
			usort($leaves, function($a, $b) {
				return $a->count - $b->count;
			});
			$index = 0;
			$totalcount = count($leaves);
			$maxparentage = 8;
			for($i = 0; $i < $maxparentage; $i++) {
				foreach($leaves as $ind => $leaf) {
					if($leaf === null) {
						continue;
					}
					$result = $quant->prune($leaf, $i);
					if($result == true) {
						$leaves[$ind] = null;
						$totalcount -= 1;
					}
					if($totalcount <= $colors) {
						break 2;
					}
				}
				$leaves = array_filter($leaves);
				usort($leaves, function($a, $b) {
					return $a->count - $b->count;
				});
			}
		}
		$leaves = array_filter($leaves);
		foreach($leaves as $leaf) {
			$newPalette[] = $leaf->toRGBA();
		}
		foreach($newPalette as &$color) {
			if($color[3] > 110) {
				$color[3] = 127;
			}
			if($color[1] > 248 && $color[1] == $color[2] && $color[2] == $color[3]) {
				$color[1] = $color[2] = $color[3] = 255;
			}
		}
		return $newPalette;
	}


	/**
	 * Ensure that an alpha value is set for input RGB palette entries.
	 *
	 * @param array $color Color array.
	 * @return array New color array, with alpha channel value if one is not provided.
	 */
	public function checkAlpha($color) {
		if(count($color) == 3) {
			$color[] = 0;
		}
		return $color;
	}

	/**
	 * Apply Floyd–Steinberg dithering algorithm to an image.
	 *
	 * http://en.wikipedia.org/wiki/Floyd%E2%80%93Steinberg_dithering
	 *
	 * @param ImageResource $im The image resource
	 * @param integer $width The width of an image
	 * @param integer $height The height of an image
	 * @param array $palette The color palette
	 * @param float $amount The dither amount(value is between 0 and 1)
	 * @return array The pixels after applying Floyd–Steinberg dithering
	 */
	private function floydSteinbergDither($im, $width, $height, &$palette, $amount)
	{
		$this->lookupCache = [];
		$palette_mode = false;
		if(count($palette) <= 256) {
			$newImage = imagecreate($width, $height);
			foreach($palette as $c) {
				imagecolorallocatealpha($newImage, $c['rgb'][0], $c['rgb'][1], $c['rgb'][2], $c['rgb'][3]);
			}
			$palette_mode = true;
		} else {
			$newImage = imagecreatetruecolor($width, $height);
			imagesavealpha($newImage, true);
			imagealphablending($newImage, false);
		}


		for ($i = 0; $i < $height; $i++) {
			if ($i === 0) {
				$currentRowColorStorage = array();
			} else {
				$currentRowColorStorage = $nextRowColorStorage;
			}

			$nextRowColorStorage = array();

			for ($j = 0; $j < $width; $j++) {
				if ($i === 0 && $j === 0) {
					$color = $this->getRGBColorAt($im, $j, $i);
				} else {
					$color = $currentRowColorStorage[$j];
				}
				$closestColor = $this->getClosestColor(array('rgb' => $color), $palette, 'rgb');
				$closestColor = $closestColor['rgb'];

				if ($j < $width - 1) {
					if ($i === 0) {
						$currentRowColorStorage[$j + 1] = $this->getRGBColorAt($im, $j + 1, $i);
					}
				}
				if ($i < $height - 1) {
					if ($j === 0) {
						$nextRowColorStorage[$j] = $this->getRGBColorAt($im, $j, $i + 1);;
					}
					if ($j < $width - 1) {
						$nextRowColorStorage[$j + 1] = $this->getRGBColorAt($im, $j + 1, $i + 1);
					}
				}

				foreach ($closestColor as $key => $channel) {
					$quantError = $color[$key] - $closestColor[$key];
					if ($j < $width - 1) {
						$currentRowColorStorage[$j + 1][$key] += $quantError * 7 / 16 * $amount;
					}
					if ($i < $height - 1) {
						if ($j > 0) {
							$nextRowColorStorage[$j - 1][$key] += $quantError * 3 / 16 * $amount;
						}
						$nextRowColorStorage[$j][$key] += $quantError * 5 / 16 * $amount;
						if ($j < $width - 1) {
							$nextRowColorStorage[$j + 1][$key] += $quantError * 1 / 16 * $amount;
						}
					}
				}

				if($palette_mode) {
					$newColor = imagecolorclosestalpha($newImage, $closestColor[0], $closestColor[1], $closestColor[2], $closestColor[3]);
				} else {
					$newColor = imagecolorallocatealpha($newImage, $closestColor[0], $closestColor[1], $closestColor[2], $closestColor[3]);
				}

				imagesetpixel($newImage, $j, $i, $newColor);
			}
		}

		return $newImage;
	}

	/**
	 * Get the closest available color from a color palette.
	 *
	 * @param array $pixel The pixel that contains the color to be calculated
	 * @param array $palette The palette that contains all the available colors
	 * @param string $mode The calculation mode, the value is 'rgb' or 'lab', 'rgb' is default value.
	 * @return array The closest color from the palette
	 */
	private function getClosestColor($pixel, &$palette, $mode = 'rgb')
	{
		$closestColor;
		$closestDistance;

		$closestColor = $this->checkColorLookupCache($pixel[$mode]);
		if($closestColor !== false) {
			return $closestColor;
		}
		unset($closestColor);

		//Naive search, distance metric is assymetic, so I can't build a partitioned space.
		foreach ($palette as $color) {
			$distance = $this->calculateAlphaDistance($pixel[$mode], $color[$mode]);
			if (isset($closestColor)) {
				if ($distance < $closestDistance) {
					$closestColor = $color;
					$closestDistance = $distance;
				} else if ($distance === $closestDistance) {
					// nothing need to do
				}
			} else {
				$closestColor = $color;
				$closestDistance = $distance;
			}
		}

		$this->recordLookupCache($pixel[$mode], $closestColor);

		return $closestColor;
	}

	/**
	 * Returns the cache lookup hit rate.
	 *
	 * @return float The hitrate as floating point between 0 and 1.
	 */
	public function getLookupHitrate() {
		return $this->cacheHits / $this->cacheLookups;
	}

	/**
	 * Checks the color lookup cache for the current pixel.
	 *
	 * @param array $pixelCoords The same pixel coordinates passed to calculateAlphaDistance.
	 * @return mixed Boolean false on no match, palette array on sucess.
	 */
	private function checkColorLookupCache($pixelCoords) {
		$hash = md5(implode('-', array_map('floor', $pixelCoords)));
		$this->cacheLookups++;
		if(!empty($this->lookupCache[$hash])) {
			$this->cacheHits++;
			return $this->lookupCache[$hash];
		} else {
			return false;
		}
	}

	/**
	 * Record an entry for our lookupCache, stores up to 4096 colors.
	 *
	 * @param array $pixelCoords The same pixel coordinates passed to calculateAlphaDistance.
	 * @param array $paletteColor the palette color to store.
	 */
	private function recordLookupCache($pixelCoords, $paletteColor) {
		$hash = md5(implode('-', array_map('floor', $pixelCoords)));
		if(empty($this->lookupCache[$hash])) {
			$this->lookupCache[$hash] = $paletteColor;
			if(count($this->lookupCache) > 4096) {
				array_shift($this->lookupCache);
			}
		}
	}

	/**
	 * Calculate the square of the euclidean distance of two colors.
	 *
	 * @param array $p The first color
	 * @param array $q The second color
	 * @return float The square of the euclidean distance of first color and second color
	 */
	private function calculateAlphaDistance($p, $q) {
		return max( pow($p[0] - $q[0], 2) , pow(( ($p[0] - $q[0]) - ($p[3] - $q[3]) ), 2) ) +
		  max( pow($p[1] - $q[1], 2) , pow(( ($p[1] - $q[1]) - ($p[3] - $q[3]) ), 2) ) +
		  max( pow($p[2] - $q[2], 2) , pow(( ($p[2] - $q[2]) - ($p[3] - $q[3]) ), 2) );
		//pow(($q[0] - $p[0]), 2) + pow(($q[1] - $p[1]), 2) + pow(($q[2] - $p[2]), 2) + pow(( ($q[3]*6) - ($q[3]*6) ), 2);
	}

	/*
	Calculation from: https://stackoverflow.com/questions/4754506/color-similarity-distance-in-rgba-color-space
	max((r₁-r₂)², (r₁-r₂ - a₁+a₂)²) +
	max((g₁-g₂)², (g₁-g₂ - a₁+a₂)²) +
	max((b₁-b₂)², (b₁-b₂ - a₁+a₂)²)

	*/

	/**
	 * Calculate the RGBA color of a pixel.
	 *
	 * @param ImageResource $im The image resource
	 * @param integer $x The x-coordinate of the pixel
	 * @param integer $y The y-coordinate of the pixel
	 * @return array An array with red, green, blue and alpha values of the pixel
	 */
	private function getRGBColorAt($im, $x, $y) {
		$index = imagecolorat($im, $x, $y);
		$r = ($index >> 16) & 0xFF;
		$g = ($index >> 8) & 0xFF;
		$b = ($index & 0xFF);
		$a = ($index >> 24);
		//Normalize all (nearly) fully-transparent pixels to black.
		if($a > 110) {
			$r = $g = $b = 0;
			$a = 127;
		}
		return array($r, $g, $b, $a);
	}

	/**
	 * Convert an RGB color to a Lab color(CIE Lab).
	 *
	 * @param array $rgb The RGB color
	 * @return array The Lab color
	 */
	private function RGBtoLab($rgb) {
		return $this->XYZtoCIELab($this->RGBtoXYZ($this->checkAlpha($rgb)));
	}

	/**
	 * Convert an RGB color to an XYZ space color, and modified to pass through alpha.
	 *
	 * observer = 2°, illuminant = D65
	 * http://easyrgb.com/index.php?X=MATH&H=02#text2
	 *
	 * @param array $rgb The RGB color
	 * @return array The XYZ space color
	 */
	private function RGBtoXYZ($rgb)
	{
		$r = $rgb[0] / 255;
		$g = $rgb[1] / 255;
		$b = $rgb[2] / 255;
		$alpha = $rgb[3] / 127;

		if ($r > 0.04045) {
			$r = pow((($r + 0.055) / 1.055), 2.4);
		} else {
			$r = $r / 12.92;
		}

		if ($g > 0.04045) {
			$g = pow((($g + 0.055) / 1.055), 2.4);
		} else {
			$g = $g / 12.92;
		}

		if ($b > 0.04045) {
			$b = pow((($b + 0.055) / 1.055), 2.4);
		} else {
			$b = $b / 12.92;
		}

		$r *= 100;
		$g *= 100;
		$b *= 100;

		return array(
			$r * 0.4124 + $g * 0.3576 + $b * 0.1805,
			$r * 0.2126 + $g * 0.7152 + $b * 0.0722,
			$r * 0.0193 + $g * 0.1192 + $b * 0.9505,
			$alpha
		);
	}

	/**
	 * Convert an XYZ space color to a CIE Lab color.
	 *
	 * observer = 2°, illuminant = D65.
	 * http://www.easyrgb.com/index.php?X=MATH&H=07#text7
	 *
	 * @param array $xyz The XYZ space color
	 * @return array The Lab color
	 */
	private function XYZtoCIELab($xyz)
	{
		$refX = 95.047;
		$refY = 100;
		$refZ = 108.883;

		$x = $xyz[0] / $refX;
		$y = $xyz[1] / $refY;
		$z = $xyz[2] / $refZ;

		if ($x > 0.008856) {
			$x = pow($x, 1/3);
		} else {
			$x = (7.787 * $x) + (16 / 116);
		}

		if ($y > 0.008856) {
			$y = pow($y, 1/3);
		} else {
			$y = (7.787 * $y) + (16 / 116);
		}

		if ($z > 0.008856) {
			$z = pow($z, 1/3);
		} else {
			$z = (7.787 * $z) + (16 / 116);
		}

		return array(
			(116 * $y) - 16,
			500 * ($x - $y),
			200 * ($y - $z),
			$alpha = $xyz[3],
		);
	}
}
