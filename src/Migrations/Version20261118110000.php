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

namespace Sylius\AdyenPlugin\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add amount and expiration date to Adyen payment links';
    }

    public function up(Schema $schema): void
    {
        if ($this->isPostgreSQL()) {
            $this->addSql('ALTER TABLE sylius_adyen_payment_link ADD amount INT DEFAULT 0 NOT NULL');
            $this->addSql('ALTER TABLE sylius_adyen_payment_link ADD expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
            $this->addSql('UPDATE sylius_adyen_payment_link SET amount = COALESCE((SELECT p.amount FROM sylius_payment p WHERE p.id = sylius_adyen_payment_link.payment_id), 0)');
            $this->addSql('ALTER TABLE sylius_adyen_payment_link ALTER COLUMN amount DROP DEFAULT');

            return;
        }

        $this->addSql('ALTER TABLE sylius_adyen_payment_link ADD amount INT DEFAULT 0 NOT NULL, ADD expires_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE sylius_adyen_payment_link SET amount = COALESCE((SELECT p.amount FROM sylius_payment p WHERE p.id = sylius_adyen_payment_link.payment_id), 0)');
        $this->addSql('ALTER TABLE sylius_adyen_payment_link CHANGE amount amount INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        if ($this->isPostgreSQL()) {
            $this->addSql('ALTER TABLE sylius_adyen_payment_link DROP amount');
            $this->addSql('ALTER TABLE sylius_adyen_payment_link DROP expires_at');

            return;
        }

        $this->addSql('ALTER TABLE sylius_adyen_payment_link DROP amount, DROP expires_at');
    }

    private function isPostgreSQL(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }
}
