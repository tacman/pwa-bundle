<?php

declare(strict_types=1);

namespace SpomkyLabs\PwaBundle\Subscriber;

use SpomkyLabs\PwaBundle\Dto\Manifest;
use SpomkyLabs\PwaBundle\Dto\ServiceWorker;
use SpomkyLabs\PwaBundle\Service\ServiceWorkerCompiler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Config\FileLocator;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use function assert;
use function count;
use function is_array;
use function is_string;
use function mb_strlen;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class PwaDevServerSubscriber implements EventSubscriberInterface
{
    private string $manifestPublicUrl;

    private null|string $serviceWorkerPublicUrl;

    private null|string $workboxPublicUrl;

    private null|string $workboxVersion;

    public function __construct(
        private FileLocator $fileLocator,
        private ServiceWorkerCompiler $serviceWorkerBuilder,
        private SerializerInterface $serializer,
        private Manifest $manifest,
        ServiceWorker $serviceWorker,
        #[Autowire('%spomky_labs_pwa.manifest.enabled%')]
        private bool $manifestEnabled,
        #[Autowire('%spomky_labs_pwa.sw.enabled%')]
        private bool $serviceWorkerEnabled,
        #[Autowire('%spomky_labs_pwa.manifest.public_url%')]
        string $manifestPublicUrl,
        private null|Profiler $profiler,
    ) {
        $this->manifestPublicUrl = '/' . trim($manifestPublicUrl, '/');
        $serviceWorkerPublicUrl = $serviceWorker->dest;
        $this->serviceWorkerPublicUrl = '/' . trim($serviceWorkerPublicUrl, '/');
        if ($serviceWorker->workbox->enabled === true) {
            $this->workboxVersion = $serviceWorker->workbox->version;
            $workboxPublicUrl = $serviceWorker->workbox->workboxPublicUrl;
            $this->workboxPublicUrl = '/' . trim($workboxPublicUrl, '/');
        } else {
            $this->workboxVersion = null;
            $this->workboxPublicUrl = null;
        }
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $pathInfo = $event->getRequest()
            ->getPathInfo();

        switch (true) {
            case $this->manifestEnabled === true && $pathInfo === $this->manifestPublicUrl:
                $this->serveManifest($event);
                break;
            case $this->serviceWorkerEnabled === true && $pathInfo === $this->serviceWorkerPublicUrl:
                $this->serveServiceWorker($event);
                break;
            case $this->serviceWorkerEnabled === true && $this->workboxVersion !== null && $this->workboxPublicUrl !== null && str_starts_with(
                $pathInfo,
                $this->workboxPublicUrl
            ):
                $this->serveWorkboxFile($event, $pathInfo);
                break;
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $headers = $event->getResponse()
->headers;
        if ($headers->has('X-Manifest-Dev') || $headers->has('X-SW-Dev')) {
            $event->stopPropagation();
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // priority higher than RouterListener
            KernelEvents::REQUEST => [['onKernelRequest', 35]],
            // Highest priority possible to bypass all other listeners
            KernelEvents::RESPONSE => [['onKernelResponse', 2048]],
        ];
    }

    private function serveManifest(RequestEvent $event): void
    {
        $this->profiler?->disable();
        $body = $this->serializer->serialize($this->manifest, 'json', [
            AbstractObjectNormalizer::SKIP_UNINITIALIZED_VALUES => true,
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ]);
        $response = new Response($body, Response::HTTP_OK, [
            'Cache-Control' => 'public, max-age=604800, immutable',
            'Content-Type' => 'application/manifest+json',
            'X-Manifest-Dev' => true,
            'Etag' => hash('xxh128', $body),
        ]);

        $event->setResponse($response);
        $event->stopPropagation();
    }

    private function serveServiceWorker(RequestEvent $event): void
    {
        $data = $this->serviceWorkerBuilder->compile();
        if ($data === null) {
            return;
        }
        $this->profiler?->disable();

        $response = new Response($data, Response::HTTP_OK, [
            'Content-Type' => 'application/javascript',
            'X-SW-Dev' => true,
            'Etag' => hash('xxh128', $data),
        ]);

        $event->setResponse($response);
        $event->stopPropagation();
    }

    private function serveWorkboxFile(RequestEvent $event, string $pathInfo): void
    {
        if (str_contains($pathInfo, '/..')) {
            return;
        }
        $asset = mb_substr($pathInfo, mb_strlen((string) $this->workboxPublicUrl));
        $resource = sprintf('@SpomkyLabsPwaBundle/Resources/workbox-v%s%s', $this->workboxVersion, $asset);
        $resourcePath = $this->fileLocator->locate($resource, null, false);
        if (is_array($resourcePath)) {
            if (count($resourcePath) === 1) {
                $resourcePath = $resourcePath[0];
            } else {
                return;
            }
        }
        if (! is_string($resourcePath)) {
            return;
        }

        $body = file_get_contents($resourcePath);
        assert(is_string($body), 'Unable to load the file content.');
        $response = new Response($body, Response::HTTP_OK, [
            'Content-Type' => 'application/javascript',
            'X-SW-Dev' => true,
            'Etag' => hash('xxh128', $body),
        ]);

        $event->setResponse($response);
        $event->stopPropagation();
    }
}
