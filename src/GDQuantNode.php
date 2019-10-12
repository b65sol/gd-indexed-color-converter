<?php
/**
 * GDQuantizer
 *
 * A quantizer (with very basic alpha support)
 *
 * Licensed under The MIT License
 *
 * @author B6.5 Solutions LLC
 * @copyright Copyright 2019 B6.5 Solutions LLC
 * @license https://github.com/ccpalettes/gd-indexed-color-converter/blob/master/LICENSE
 **/

class GDQuantNode {

	protected $tree = [];
	protected $bits;
	public $count = 0;
	protected $parent = null;
	protected $folded = [];
	public $mykey = null;
	public $ignore = false;

	public function getTree() {
		return $this->tree;
	}

	/**
	 * Retrieves a node's parent.
	 *
	 * @return GDQuantNode Parent node.
	 */
	public function getParent() {
		return $this->parent;
	}

	/**
	 * Calculate 4D distance, greater weight is given to final channel.
	 *
	 * @param array $color1 First color array, as returned by toRGBA
	 * @param array $color2 Second color array, as returned by toRGBA
	 */
	public static function calcDistance($a, $b) {
		return pow(($a[0] - $b[0]), 2) + pow(($a[1] - $b[1]), 2) + pow(($a[2] - $b[2]), 2) +
			pow(( ($a[3]) - ($b[3]) ), 2);
	}



	/**
	 * Prunes a node from the tree, with a selection for maxparentage to limit quantization error.
	 *
	 * @param GDQuantNode $node Node to prune.
	 * @param int $maxparentage Highest level of parent for leaves to consider. If there are
	 *  no candidates within this level, increase this value and attempt again. [0 == only consider siblings.]
	 * @return bool true if successful, false if not.
	 */
	public function prune($node, $maxparentage = 0) {
		$parent = $node->getParent();
		for($i = 0; $i < $maxparentage; $i++) {
			if($cparrent = $parent->getParent()) {
				if($cparrent !== null) {
					$parent = $cparrent;
				}
			}
		}
		$candidates = $parent->findLeaves();
		//i.e. only ourselves.
		if(count($candidates) == 1) {
			return false;
		}
		$distances = [];
		$rgba = $node->toRGBA();
		foreach($candidates as $candidate) {
			if($candidate === $node) {
				continue;
			}
			$cand_rgba = $candidate->toRGBA();
			$distance = $this->calcDistance($rgba, $cand_rgba);
			$distances[] = [$distance, $candidate];
		}
		usort($distances, function($a, $b) {
			return $a[0] - $b[0];
		});
		$distances[0][1]->count += $node->count;
		$node_parent = $node->getParent();
		$node_parent->removeLeaf($node);
		return true;
	}

	/**
	 * Remove a leaf node.
	 *
	 * @param GDQuantNode $node Node to remove.
	 */
	public function removeLeaf($node) {
		if(empty($this->tree[$node->mykey])) {
			return;
		}
		$this->tree[$node->mykey]->ignore = true;
	}


	/**
	 * Find all the leaf nodes of a this node.
	 *
	 * @return array Leaf nodes.
	 */
	public function findLeaves() {
		$list = [];
		if(empty($this->tree) && $this->ignore == false) {
			return [$this];
		} elseif (empty($this->tree) && $this->ignore) {
			return [];
		}
		foreach($this->tree as $key => $node) {
			$results = $node->findLeaves();
			$list = array_merge($list, $results);
		}
		return $list;
	}

	/**
	 * To RGBA
	 */
	public function toRGBA() {
		$bitlists = [];
		$cval = $this;
		while($cval->mykey !== null) {
			$bitlists[] = $cval->mykey;
			$cval = $cval->parent;
		}
		$r = $g = $b = $a = 0;
		$bitlists = array_reverse($bitlists, false);
		foreach($bitlists as $ind => $bitval) {
			$r += ($bitval & 1);
			$g += (($bitval & 2) >> 1);
			$b += (($bitval & 4) >> 2);
			$a += (($bitval & 8) >> 3);
			$r = $r << 1;
			$g = $g << 1;
			$b = $b << 1;
			$a = $a << 1;
		}
		$r = $r >> 1;
		$g = $g >> 1;
		$b = $b >> 1;
		$a = $a >> 1;

		for($i = 0; $i < 8 - $this->bits; $i++) {
			$r = $r << 1;
			$g = $g << 1;
			$b = $b << 1;
			$a = $a << 1;
		}
		return [$r, $g, $b, $a];
	}

	/**
	 * Add a pixel to the quantnode's tree.
	 *
	 * @param int $r Red.
	 * @param int $g Green.
	 * @param int $b Blue.
	 * @param int $a Alpha.
	 */
	public function add($r, $g, $b, $a) {
		$rbits = $this->tobits($r, 8, $this->bits);
		$gbits = $this->tobits($g, 8, $this->bits);
		$bbits = $this->tobits($b, 8, $this->bits);
		$abits = $this->tobits($a, 8, $this->bits);
		$this->count ++;

		$root_r = array_shift($rbits);
		$root_g = array_shift($gbits);
		$root_b = array_shift($bbits);
		$root_a = array_shift($abits);
		$rootkey = $root_r + ($root_g*2) + ($root_b*4) + ($root_a*8);

		if(empty($this->tree[$rootkey])) {
			$this->tree[$rootkey] = new GDQuantNode($this->bits, $this, $rootkey);
		}
		$this->tree[$rootkey]->childAdd($rbits, $gbits, $bbits, $abits);
	}

	/**
	 * Accepts bit arrays for the channels and continue to populate the tree.
	 *
	 * @param array $rbits remaining red bits.
	 * @param array $gbits remaining green bits.
	 * @param array $bbits remaining blue bits.
	 * @param array $abits remaining alpha bits.
	 */
	protected function childAdd($rbits, $gbits, $bbits, $abits) {
		$this->count++;
		if(count($rbits) == 0) {
			return;
		}
		$root_r = array_shift($rbits);
		$root_g = array_shift($gbits);
		$root_b = array_shift($bbits);
		$root_a = array_shift($abits);
		$rootkey = $root_r + ($root_g*2) + ($root_b*4) + ($root_a*8);

		if(empty($this->tree[$rootkey])) {
			$this->tree[$rootkey] = new GDQuantNode($this->bits, $this, $rootkey);
		}
		$this->tree[$rootkey]->childadd($rbits, $gbits, $bbits, $abits);
	}

	/**
	 * Convert a number to a string of bits.
	 *
	 * @param int $number To to split.
	 * @param int $overallbits Number of bits overall. (Max 16)
	 * @param int $significantbits Number of significant bits to return.
	 */
	public function tobits($number, $overallbits, $significantbits) {
		$bits = [];
		for($i = $overallbits - $significantbits; $i < $overallbits; $i++) {
			$bits[] = ($number >> $i) & 1;
		}
		return array_reverse($bits);
	}

	/**
	 * Construct the tree/node.
	 *
	 * @param int $significant_bits Number of bits to consider for per channel.
	 * @param GDQuantNode $parent Parent node.
	 * @param int $mykey This node's binary identifier key. Null for root tree.
	 */
	public function __construct($significant_bits = 5, $parent = null, $mykey = null) {
		$this->bits = $significant_bits;
		$this->parent = $parent;
		$this->mykey = $mykey;
	}
}
