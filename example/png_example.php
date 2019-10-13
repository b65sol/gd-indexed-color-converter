<?php
/**
 * An example of GDIndexedColorConverter.
 *
 * Licensed under The MIT License
 *
 * @author Jeremy Yu
 * @copyright Copyright (c) 2014 Jeremy Yu
 * @license https://github.com/ccpalettes/gd-indexed-color-converter/blob/master/LICENSE
 */

require_once('../src/GDIndexedColorConverter.php');



// color palette
$palette = array(
	array(0, 0, 0),  // black color
	//array(255, 255, 255),  // white color
	//array(255, 0, 0),  // red color
	//array(0, 255, 0),  // green color
	//array(0, 0, 255),  // blue color
        array(0, 0, 0, 127), //transparency
        array(0, 0, 0, 64), //half-black transparency
		array(255,255,255,127), //white-transparency
);

// dither amounts
$dithers = array(0.2, 0.4, 0.8);

// the image file path
$file_path = '76457185_p0.png';

// get image information
$image_info = getimagesize($file_path);
if (!$image_info) {
	exit('Fail to get image information.');
}

$image_type = $image_info[2];

if ($image_type === IMAGETYPE_PNG) {
	// create image
	$image = imagecreatefrompng($file_path);
} else {
	exit('The image is not PNG format');
}

if ($image) {
	// indexed color converter
	$converter = new GDIndexedColorConverter();
	$pal = $converter->quantize($image, 240, 5);

	// convert the image into indexed color mode
	foreach($dithers as $dither_amount) {
		$new_image = $converter->convertToIndexedColor($image, $pal, $dither_amount);

		// save the new image
		imagepng($new_image, 'witch_dither_'.$dither_amount.'.png', 8);

		echo "Dither amount: $dither_amount. Done. Cache rate:".sprintf("%0.2f%% \n", $converter->getLookupHitrate()*100);

		// free memory of the new image
		imagedestroy($new_image);
	}

	// free memory
	imagedestroy($image);
} else {
	exit('Fail to load the image.');
}

echo "Now testing a more difficult case: harsh.png\n";

// the image file path
$file_path = 'harsh.png';

// get image information
$image_info = getimagesize($file_path);
if (!$image_info) {
	exit('Fail to get image information.');
}

$image_type = $image_info[2];

if ($image_type === IMAGETYPE_PNG) {
	// create image
	$image = imagecreatefrompng($file_path);
} else {
	exit('The image is not PNG format');
}

if ($image) {
	// indexed color converter
	$converter = new GDIndexedColorConverter();
	$pal = $converter->quantize($image, 240, 5);

	// convert the image into indexed color mode
	foreach($dithers as $dither_amount) {
		$new_image = $converter->convertToIndexedColor($image, $pal, $dither_amount);

		// save the new image
		imagepng($new_image, 'harsh_'.$dither_amount.'.png', 8);

		echo "Dither amount: $dither_amount. Done. Cache rate:".sprintf("%0.2f%% \n", $converter->getLookupHitrate()*100);

		// free memory of the new image
		imagedestroy($new_image);
	}

	// free memory
	imagedestroy($image);
} else {
	exit('Fail to load the image.');
}
