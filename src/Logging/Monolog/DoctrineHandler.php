<?php

/*
 * This file is part of the Sylius Adyen Plugin package.
 *
 * (c) Sylius Sp. z o.o.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Sylius\AdyenPlugin\Logging\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Sylius\AdyenPlugin\Factory\LogFactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;

final class DoctrineHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly LogFactoryInterface $logFactory,
        private readonly RepositoryInterface $repository,
        private readonly RequestStack $requestStack,
    ) {
        parent::__construct();
    }

    protected function write(array|LogRecord $record): void
    {
        $log = $this->logFactory->create(substr($record['message'], 0, 5000), $record['level'], 0, $this->addSessionToken());

        $this->repository->add($log);
    }

    private function addSessionToken(): string
    {
        try {
            $session = $this->requestStack->getSession();
        } catch (SessionNotFoundException $e) {
            return '';
        }
        if (!$session->isStarted()) {
            return '';
        }

        $sessionId = substr($session->getId(), 0, 8) ?: '????????';
        $sessionId = $sessionId . '-' . substr(uniqid('', true), -8);

        return $sessionId;
    }
}
