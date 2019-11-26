<?php
/**
 * Tests regex patterns.
 *
 * @package Broken_Link_Fixer
 */

use Broken_Link_Fixer\CLI\Base;

/**
 * Tests regex patterns.
 */
class Test_Regex extends WP_UnitTestCase {

	/**
	 * Tests the STANDALONE_URL_MATCH_REGEX constant
	 */
	public function test_standalone_url_match_regex() {
		$content = <<<EOT
I made these today for my infant twins. They loved 'em! I wrote about it in their blog: http://babieslovebakedgoods.blogspot.com/2011/10/bran-tastic-muffins.html.

Thanks for a great recipe!
EOT;
		preg_match_all( Base::STANDALONE_URL_MATCH_REGEX, $content, $matches );
		$this->assertCount(1, $matches[0]);
		$this->assertTrue(isset($matches['before'][0]));
		$this->assertTrue(isset($matches['after'][0]));
		$this->assertEquals('http://babieslovebakedgoods.blogspot.com/2011/10/bran-tastic-muffins.html', $matches['url'][0]);
	}
}
