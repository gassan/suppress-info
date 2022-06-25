<?php

declare(strict_types=1);

namespace Gassan\Composer\SuppressInfo;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\PartialComposer;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PostFileDownloadEvent;
use Composer\Util\Http\CurlResponse;
use Composer\Util\Http\Response;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    protected $composer;
    protected $io;

    /**
     * @var array{string: array|string]} RepoUrl => regex
     */
    private $filterRe;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;

        $this->filterRe = array_merge(
            $this->readSuppressInfos($this->composer->getPluginManager()->getGlobalComposer()),
            $this->readSuppressInfos($this->composer)
        );
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::POST_FILE_DOWNLOAD => 'suppressInfo',
        ];
    }

    public function suppressInfo(PostFileDownloadEvent $event)
    {
        if ('metadata' !== $event->getType()) {
            return;
        }

        $repoRes = [];
        foreach ($this->filterRe as $repo => $res) {
            if (0 === strpos($event->getUrl(), $repo)) {
                $repoRes = array_merge($repoRes, $res);
            }
        }

        if (empty($repoRes)) {
            return;
        }

        $context = $event->getContext();

        /**
         * @var CurlResponse $response
         */
        $response = $context['response'];

        $json = $response->decodeJson();

        $changed = false;

        // composer 2.2
        foreach ($json['infos'] ?? [] as $k => $spec) {
            if (isset($spec['message'])) {
                foreach ($repoRes as $re) {
                    if (preg_match($re, $spec['message'])) {
                        unset($json['infos'][$k]);

                        $changed = true;
                    }
                }
            }
        }
        if ($changed) {
            if (empty($json['infos'])) {
                unset($json['infos']);
            } else {
                $json['infos'] = array_values($json['infos']);
            }
        }

        if ($json['info'] ?? null) {
            foreach ($repoRes as $re) {
                if (preg_match($re, $json['info'])) {
                    unset($json['info']);
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $newBody = JsonFile::encode($json, 0);

            $reflectionClass = new \ReflectionClass(Response::class);
            $bodyProp = $reflectionClass->getProperty('body');
            $bodyProp->setAccessible(true);
            $bodyProp->setValue($response, $newBody);
            $bodyProp->setAccessible(false);
        }
    }

    private function readSuppressInfos(?PartialComposer $composer): array
    {
        $infos = [];

        if (null !== $composer) {
            $extra = $composer->getPackage()->getExtra();
            if (isset($extra['suppress-info'])) {
                foreach ((array) $extra['suppress-info'] as $repo => $res) {
                    if ($res) {
                        if (\is_int($repo)) {
                            $repo = 'https://repo.packagist.org/';
                        }
                        $res = (array) $res;
                        foreach ($res as $re) {
                            $err = '';
                            set_error_handler(static function ($code, $message) use (&$err) {
                                $err = $message;
                            }, \E_WARNING);
                            $valid = false !== preg_match($re, '');
                            restore_error_handler();
                            if ($valid) {
                                $infos[$repo][] = $re;
                            } else {
                                $msg = sprintf('<warning>Plugin: gassan/suppress-info: Regexp "<fg=black;bg=yellow;options=bold>%s</>" is invalid. Error: %s</warning>', $re, $err);
                                $this->io->writeError($msg);
                            }
                        }
                    }
                }
            }
        }

        return $infos;
    }
}
