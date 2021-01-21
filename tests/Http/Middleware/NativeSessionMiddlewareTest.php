<?php

namespace Tests\Baldinof\RoadRunnerBundle\Http\Middleware;

use Yiisoft\Cookies\Cookie;
use Yiisoft\Cookies\CookieCollection;
use function Baldinof\RoadRunnerBundle\consumes;
use Baldinof\RoadRunnerBundle\Exception\HeadersAlreadySentException;
use Baldinof\RoadRunnerBundle\Http\Middleware\NativeSessionMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class NativeSessionMiddlewareTest extends TestCase
{
    private $middleware;

    public function setUp(): void
    {
        $this->middleware = new NativeSessionMiddleware();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_sessions_works()
    {
        $response = $this->process($this->emptyRequest());

        $this->assertEquals('1', (string) $response->getBody());

        $sessionedRequest = $this->requestWithCookiesFrom($response);

        $sessionedResponse = $this->process($sessionedRequest);

        // The session has been re-used
        $this->assertEquals('2', (string) $sessionedResponse->getBody());

        // A new session has been created
        $noSessionResponse = $this->process($this->emptyRequest());

        $this->assertEquals('1', (string) $noSessionResponse->getBody());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_it_uses_php_params()
    {
        $lifetime = 600;
        $now = time();
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/hello',
            'domain' => 'example.org',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        $response = $this->process($this->emptyRequest());

        $cookie = CookieCollection::fromResponse($response)->get(session_name());

        $this->assertEquals($cookie->getPath(), '/hello');
        $this->assertEquals($cookie->getDomain(), 'example.org');
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertEquals(Cookie::SAME_SITE_STRICT, $cookie->getSameSite());
        $this->assertEquals($now + $lifetime, $cookie->getExpires()->format('U'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_it_closes_session_if_the_handler_throws()
    {
        $expectedException = new \Exception('Error during handler');
        try {
            $this->process($this->emptyRequest(), function ($req) use ($expectedException) {
                session_start();

                throw $expectedException;
            });
        } catch (\Throwable $e) {
            if ($e !== $expectedException) {
                throw $e;
            }

            $this->assertEquals(PHP_SESSION_NONE, session_status());
        }
    }

    public function test_it_throws_if_headers_already_sent()
    {
        if (!headers_sent()) {
            $this->markAsRisky();
        }

        $this->expectException(HeadersAlreadySentException::class);

        $this->process($this->emptyRequest());
    }

    private function requestWithCookiesFrom(ResponseInterface $response): ServerRequestInterface
    {
        $request = $this->emptyRequest();

        if ($response->hasHeader('Set-Cookie')) {
            $request = $request->withHeader('Cookie', $response->getHeaderLine('Set-Cookie'));
        }

        return $request;
    }

    private function process(ServerRequestInterface $request, ?callable $handler = null): ResponseInterface
    {
        if (null === $handler) {
            $handler = function (ServerRequestInterface $request): ResponseInterface {
                session_start();

                $counter = ($_SESSION['counter'] ?? 0) + 1;

                $_SESSION['counter'] = $counter;

                return new Response(200, [], (string) $counter);
            };
        }

        $it = $this->middleware->process($request, new class($handler) implements RequestHandlerInterface {
            public function __construct(callable $handler)
            {
                $this->handler = $handler;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->handler)($request);
            }
        });

        $resp = $it->current();

        consumes($it);

        return $resp;
    }

    private function emptyRequest(): ServerRequestInterface
    {
        return new ServerRequest('GET', 'https://example.org');
    }
}
