<?php

namespace Baldinof\RoadRunnerBundle\Http\Middleware;

use Baldinof\RoadRunnerBundle\Exception\HeadersAlreadySentException;
use Baldinof\RoadRunnerBundle\Http\IteratorMiddlewareInterface;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Cookies\Cookie;
use Yiisoft\Cookies\CookieCollection;

class NativeSessionMiddleware implements IteratorMiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $next): \Iterator
    {
        if (headers_sent()) {
            throw new HeadersAlreadySentException('Headers has already been sent. Something have been echoed on stdout.');
        }

        unset($_SESSION);

        $oldId = self::cookiesFromRequest($request)->getValue(session_name(), '');

        session_id($oldId); // Set to current session or reset to nothing

        try {
            $response = $next->handle($request);

            $newId = session_id();

            if ($newId !== $oldId) {
                // A session has been started or the id has changed: send the cookie again
                $response = $this->addSessionCookie($response, $newId);
            }

            yield $response;
        } finally {
            if (PHP_SESSION_ACTIVE === session_status()) {
                session_write_close();
            }
        }
    }

    private static function cookiesFromRequest(ServerRequest $request): CookieCollection
    {
        $cookies = [];
        $allCookiesString = $request->getHeaderLine('Cookie');

        foreach (self::splitOnAttributeDelimiter($allCookiesString) as $cookieString)
        if ($cookieString) {
            $cookies[] = Cookie::fromCookieString($cookieString);
        }

        return new CookieCollection($cookies);
    }

    /** @return string[] */
    private static function splitOnAttributeDelimiter(string $string) : array
    {
        $splitAttributes = preg_split('@\s*[;]\s*@', $string);

        assert(is_array($splitAttributes));

        return array_filter($splitAttributes);
    }

    private function addSessionCookie(ResponseInterface $response, string $sessionId): ResponseInterface
    {
        $params = session_get_cookie_params();

        $cookie = (new Cookie(session_name(), $sessionId))
            ->withPath($params['path'])
            ->withDomain($params['domain'])
            ->withSecure($params['secure'])
            ->withHttpOnly($params['httponly']);

        if ($params['lifetime'] > 0) {
            $cookie = $cookie->withExpires(\DateTime::createFromFormat('U', time() + $params['lifetime']));
        }

        if ($params['samesite']) {
            $cookie = $cookie->withSameSite($params['samesite']);
        }

        return $cookie->addToResponse($response);
    }
}
