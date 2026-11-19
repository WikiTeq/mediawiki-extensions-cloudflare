<?php

namespace MediaWiki\Extension\Cloudflare\Tests;

use HashConfig;
use MediaWiki\Extension\Cloudflare\CloudflareAPIRequester;
use MediaWiki\Extension\Cloudflare\HookHandler;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Cloudflare\HookHandler
 */
class HookHandlerTest extends MediaWikiIntegrationTestCase {

	private const PURGE_HOST = 'bucket.s3.us-east-va.io.cloud.ovh.us';

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example.org',
			'CanonicalServer' => 'https://wiki.example.org',
			'InternalServer' => false,
		] );
	}

	/**
	 * Run the hook and return the URLs passed to cachePurge(), or null when it was not called.
	 *
	 * @param array $settings Extension settings
	 * @param string $fileUrl
	 * @param string[] $thumbUrls
	 * @return string[]|null
	 */
	private function runFilePurge( array $settings, string $fileUrl, array $thumbUrls = [] ): ?array {
		$captured = null;
		$requester = $this->createMock( CloudflareAPIRequester::class );
		$requester->method( 'cachePurge' )->willReturnCallback(
			static function ( array $urls ) use ( &$captured ) {
				$captured = $urls;
			}
		);
		$config = new HashConfig( $settings + [
			'CloudflarePurgeFile' => true,
			'CloudflarePurgePage' => false,
			'CloudflarePurgeFileHostname' => null,
		] );
		$handler = new HookHandler( $config, $requester, $this->createMock( TitleFactory::class ) );
		$file = $this->createMock( \File::class );
		$file->method( 'getUrl' )->willReturn( $fileUrl );
		$handler->onLocalFilePurgeThumbnails( $file, false, $thumbUrls );
		return $captured;
	}

	public function testOriginalAndThumbnailAreRewritten() {
		$urls = $this->runFilePurge(
			[ 'CloudflarePurgeFileHostname' => self::PURGE_HOST ],
			'https://images.example.com/a/ab/Example.png',
			[ 'https://images.example.com/thumb/a/ab/Example.png/120px-Example.png' ]
		);
		$this->assertSame( [
			'https://' . self::PURGE_HOST . '/a/ab/Example.png',
			'https://' . self::PURGE_HOST . '/thumb/a/ab/Example.png/120px-Example.png',
		], $urls );
	}

	public function testOnlyHostIsReplaced() {
		$rest = ':8443/images.example.com/A%20b.png?u=images.example.com&x=%2F#images.example.com';
		$urls = $this->runFilePurge(
			[ 'CloudflarePurgeFileHostname' => self::PURGE_HOST ],
			'https://user:pw@images.example.com' . $rest
		);
		$this->assertSame( [ 'https://user:pw@' . self::PURGE_HOST . $rest ], $urls );
	}

	public function testRelativeUrlIsExpandedBeforeRewrite() {
		$urls = $this->runFilePurge(
			[ 'CloudflarePurgeFileHostname' => self::PURGE_HOST ],
			'/images/a/ab/Example.png'
		);
		$this->assertSame( [ 'https://' . self::PURGE_HOST . '/images/a/ab/Example.png' ], $urls );
	}

	public function testProtocolRelativeUrlIsExpandedBeforeRewrite() {
		$urls = $this->runFilePurge(
			[ 'CloudflarePurgeFileHostname' => self::PURGE_HOST ],
			'//images.example.com/a/ab/Example.png'
		);
		$this->assertSame( [ 'https://' . self::PURGE_HOST . '/a/ab/Example.png' ], $urls );
	}

	/**
	 * @dataProvider provideNoOverride
	 */
	public function testNoOverrideKeepsUrls( $value ) {
		$urls = $this->runFilePurge(
			[ 'CloudflarePurgeFileHostname' => $value ],
			'https://images.example.com/a/ab/Example.png',
			[ 'https://images.example.com/thumb/a/ab/Example.png/120px-Example.png' ]
		);
		$this->assertSame( [
			'https://images.example.com/a/ab/Example.png',
			'https://images.example.com/thumb/a/ab/Example.png/120px-Example.png',
		], $urls );
	}

	public static function provideNoOverride(): array {
		return [
			'null' => [ null ],
			'empty string' => [ '' ],
			'scheme included' => [ 'https://bucket.example.com' ],
			'path included' => [ 'bucket.example.com/path' ],
			'port included' => [ 'bucket.example.com:8080' ],
			'not a string' => [ [ 'bucket.example.com' ] ],
			'label over 63 chars' => [ str_repeat( 'a', 64 ) . '.example.com' ],
			'ipv6' => [ '[2001:db8::1]' ],
			'trailing newline' => [ "bucket.example.com\n" ],
		];
	}

	public function testFilePurgeDisabled() {
		$urls = $this->runFilePurge(
			[ 'CloudflarePurgeFile' => false, 'CloudflarePurgeFileHostname' => self::PURGE_HOST ],
			'https://images.example.com/a/ab/Example.png'
		);
		$this->assertNull( $urls );
	}

	public function testPageUrlsAreNotRewritten() {
		$captured = null;
		$requester = $this->createMock( CloudflareAPIRequester::class );
		$requester->method( 'cachePurge' )->willReturnCallback(
			static function ( array $urls ) use ( &$captured ) {
				$captured = $urls;
			}
		);
		$title = $this->createMock( Title::class );
		$title->method( 'getFullURL' )->willReturn( 'https://wiki.example.org/wiki/Main_Page' );
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromPageIdentity' )->willReturn( $title );
		$config = new HashConfig( [
			'CloudflarePurgeFile' => true,
			'CloudflarePurgePage' => true,
			'CloudflarePurgeFileHostname' => self::PURGE_HOST,
		] );
		$handler = new HookHandler( $config, $requester, $titleFactory );
		$handler->onArticlePurge( $this->createMock( \WikiPage::class ) );
		$this->assertSame( [ 'https://wiki.example.org/wiki/Main_Page' ], $captured );
	}

	/**
	 * @dataProvider provideMalformedUrls
	 */
	public function testMalformedUrlsAreSkipped( string $url ) {
		$urls = $this->runFilePurge(
			[ 'CloudflarePurgeFileHostname' => self::PURGE_HOST ],
			$url,
			[ 'https://images.example.com/ok.png' ]
		);
		$this->assertSame( [ 'https://' . self::PURGE_HOST . '/ok.png' ], $urls );
	}

	public static function provideMalformedUrls(): array {
		return [
			'empty host' => [ 'https:///a.png' ],
			'empty host with userinfo' => [ 'https://user@/a.png' ],
			'port out of range' => [ 'https://images.example.com:99999/a.png' ],
			'two at signs' => [ 'https://a@b@images.example.com/a.png' ],
			'invalid percent escape' => [ 'https://images.example.com/bad%ZZ.png' ],
			'invalid ipv6 host' => [ 'https://[:::]/a.png' ],
			'newline before expansion' => [ "https://images.example.com/a\n.png" ],
		];
	}

	public function testIpv6SourceHostIsRewritten() {
		$urls = $this->runFilePurge(
			[ 'CloudflarePurgeFileHostname' => self::PURGE_HOST ],
			'https://[2001:db8::1]:8080/a.png'
		);
		$this->assertSame( [ 'https://' . self::PURGE_HOST . ':8080/a.png' ], $urls );
	}

	public function testTripleColonOutsideIpv6HostIsKept() {
		$urls = $this->runFilePurge(
			[ 'CloudflarePurgeFileHostname' => self::PURGE_HOST ],
			'https://[2001:db8::1]/a:::b.png?token=:::'
		);
		$this->assertSame( [ 'https://' . self::PURGE_HOST . '/a:::b.png?token=:::' ], $urls );
	}
}
