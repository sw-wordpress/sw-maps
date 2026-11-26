<?php

declare(strict_types=1);

namespace SWMaps\Tests\Unit\Network;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWMaps\Core\Network\GuardedHttpClient;
use SWMaps\Core\Network\HostResolverInterface;
use SWMaps\Core\Network\HttpException;
use SWMaps\Core\Network\HttpResponse;
use SWMaps\Core\Network\HttpTransportInterface;
use SWMaps\Core\Network\UnsafeUrlException;
use SWMaps\Core\Network\UrlGuard;

final class UrlGuardTest extends TestCase
{
    /** @param array<string, list<string>> $dns */
    private function guard(array $dns = [], array $allowed = []): UrlGuard
    {
        $resolver = new class ($dns) implements HostResolverInterface {
            /** @param array<string, list<string>> $dns */
            public function __construct(private readonly array $dns)
            {
            }

            public function resolve(string $host): array
            {
                return $this->dns[$host] ?? [];
            }
        };

        return new UrlGuard($resolver, $allowed);
    }

    /** @return iterable<string, array{string}> */
    public static function blocked(): iterable
    {
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'gopher' => ['gopher://example.org/'];
        yield 'ftp' => ['ftp://example.org/x'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'no host' => ['http:///path'];
        yield 'not a url' => ['not a url'];
        yield 'credentials' => ['http://user:pass@public.example/'];
        yield 'user only' => ['http://admin@public.example/'];
        yield 'userinfo trick' => ['http://public.example@127.0.0.1/'];
        yield 'backslash' => ['http://public.example\\@127.0.0.1/'];
        yield 'whitespace' => ["http://public.example/a b"];
        yield 'newline' => ["http://public.example/\r\nHost: x"];
        yield 'loopback' => ['http://127.0.0.1/'];
        yield 'loopback range' => ['http://127.255.255.254/'];
        yield 'localhost name' => ['http://localhost/'];
        yield 'private 10' => ['http://10.0.0.5/'];
        yield 'private 172' => ['https://172.16.0.1/'];
        yield 'private 192' => ['http://192.168.1.1/'];
        yield 'link local metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'unspecified' => ['http://0.0.0.0/'];
        yield 'cgnat' => ['http://100.64.0.1/'];
        yield 'benchmark' => ['http://198.18.0.1/'];
        yield 'protocol assignment' => ['http://192.0.0.8/'];
        yield 'multicast' => ['http://224.0.0.1/'];
        yield 'ipv6 loopback' => ['http://[::1]/'];
        yield 'ipv6 unspecified' => ['http://[::]/'];
        yield 'ipv6 unique local' => ['http://[fd00::1]/'];
        yield 'ipv6 link local' => ['http://[fe80::1]/'];
        yield 'ipv6 multicast' => ['http://[ff02::1]/'];
        yield 'ipv6 documentation' => ['http://[2001:db8::1]/'];
        yield 'ipv4 mapped loopback' => ['http://[::ffff:127.0.0.1]/'];
        yield 'ipv4 mapped private' => ['http://[::ffff:10.0.0.1]/'];
        yield 'ipv4 compatible loopback' => ['http://[::127.0.0.1]/'];
        yield 'nat64 private' => ['http://[64:ff9b::10.0.0.1]/'];
        yield 'decimal ip' => ['http://2130706433/'];
        yield 'hex ip' => ['http://0x7f000001/'];
        yield 'short ip' => ['http://127.1/'];
        yield 'octal ip' => ['http://0177.0.0.1/'];
        yield 'dns to private' => ['http://internal.example/'];
        yield 'dns mixed' => ['http://mixed.example/'];
        yield 'dns to ipv6 private' => ['http://v6.example/'];
        yield 'does not resolve' => ['http://nowhere.example/'];
        yield 'trailing dot' => ['http://public.example./'];
    }

    #[DataProvider('blocked')]
    public function testUnsafeTargetsAreRefused(string $url): void
    {
        $guard = $this->guard([
            'public.example' => ['93.184.216.34'],
            'internal.example' => ['10.1.2.3'],
            'mixed.example' => ['93.184.216.34', '192.168.0.9'],
            'v6.example' => ['fd12::1'],
        ]);
        $this->expectException(UnsafeUrlException::class);
        $guard->check($url);
    }

    /** @return iterable<string, array{string}> */
    public static function allowed(): iterable
    {
        yield 'http' => ['http://public.example/tiles/{z}'];
        yield 'https with port' => ['https://public.example:8443/x?y=1'];
        yield 'public ipv4' => ['http://93.184.216.34/'];
        yield 'public ipv6' => ['http://[2606:2800:220:1:248:1893:25c8:1946]/'];
        yield 'mapped public' => ['http://[::ffff:93.184.216.34]/'];
        yield 'upper case scheme' => ['HTTPS://Public.Example/'];
    }

    #[DataProvider('allowed')]
    public function testPublicTargetsPass(string $url): void
    {
        $this->guard(['public.example' => ['93.184.216.34']])->check(str_replace('{z}', '1', $url));
        self::assertTrue(true);
    }

    public function testAdministratorAllowlistExemptsOnlyThatHost(): void
    {
        $guard = $this->guard(['geo.lan' => ['10.0.0.8'], 'other.lan' => ['10.0.0.9']], ['GEO.lan']);
        $guard->check('http://geo.lan:8080/search');
        $this->expectException(UnsafeUrlException::class);
        $guard->check('http://other.lan/');
    }

    public function testRedirectTargetsAreResolvedAgainstTheirSource(): void
    {
        self::assertSame('https://b.example/x', UrlGuard::resolveRedirect('https://b.example/x', 'http://a.example/y'));
        self::assertSame('http://a.example:81/new', UrlGuard::resolveRedirect('/new', 'http://a.example:81/old/path'));
        self::assertSame('http://a.example/old/new', UrlGuard::resolveRedirect('new', 'http://a.example/old/path'));
        self::assertSame('https://c.example/z', UrlGuard::resolveRedirect('//c.example/z', 'https://a.example/'));
        self::assertSame('gopher://x/', UrlGuard::resolveRedirect('gopher://x/', 'http://a.example/'));
    }

    /** @param list<HttpResponse|\Throwable> $responses */
    private function client(array $responses, array &$seen = []): GuardedHttpClient
    {
        $transport = new class ($responses, $seen) implements HttpTransportInterface {
            /** @param list<HttpResponse|\Throwable> $responses */
            public function __construct(private array $responses, private array &$seen)
            {
            }

            public function get(string $url, array $headers, int $timeoutSeconds, int $maxBytes): HttpResponse
            {
                $this->seen[] = [$url, $maxBytes];
                $next = array_shift($this->responses) ?? new HttpResponse(200, [], 'end');
                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next;
            }
        };

        return new GuardedHttpClient($transport, $this->guard(['public.example' => ['93.184.216.34'], 'internal.example' => ['10.0.0.1']]), 5, 1000);
    }

    public function testRedirectsAreFollowedAndEveryHopIsChecked(): void
    {
        $seen = [];
        $ok = $this->client([new HttpResponse(302, ['location' => '/next'], ''), new HttpResponse(200, ['content-type' => 'text/plain'], 'body')], $seen)->get('http://public.example/start', [], 50);
        self::assertSame(['body', 'text/plain'], [$ok->body, $ok->header('Content-Type')]);
        self::assertSame([['http://public.example/start', 50], ['http://public.example/next', 50]], $seen);

        $seen = [];
        try {
            $this->client([new HttpResponse(301, ['location' => 'http://169.254.169.254/latest/meta-data/'], '')], $seen)->get('http://public.example/start');
            self::fail('expected the redirect to the metadata service to be refused');
        } catch (UnsafeUrlException) {
            self::assertCount(1, $seen, 'the forbidden target was never requested');
        }
    }

    public function testRedirectLoopsAndPrivateStartAreRefused(): void
    {
        $loop = array_fill(0, 10, new HttpResponse(302, ['location' => 'http://public.example/again'], ''));
        $this->expectException(UnsafeUrlException::class);
        $this->client($loop)->get('http://public.example/');
    }

    public function testPrivateStartIsRefusedBeforeAnyRequest(): void
    {
        $seen = [];
        try {
            $this->client([], $seen)->get('http://internal.example/');
            self::fail('expected refusal');
        } catch (UnsafeUrlException) {
            self::assertSame([], $seen);
        }
    }

    public function testTransportFailuresPropagateAndNonRedirectsAreReturned(): void
    {
        $notFound = $this->client([new HttpResponse(404, [], 'nope'), new HttpResponse(302, [], '')])->get('http://public.example/');
        self::assertSame(404, $notFound->status);
        self::assertNull($notFound->header('x-missing'));
        $this->expectException(HttpException::class);
        $this->client([new HttpException('timeout')])->get('http://public.example/');
    }
}
