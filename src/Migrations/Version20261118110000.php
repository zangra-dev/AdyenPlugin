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

use Doctrine\DBAL\Schema\Schema;
use Sylius\Bundle\CoreBundle\Doctrine\Migrations\AbstractPostgreSQLMigration;

final class Version20261118110000 extends AbstractPostgreSQLMigration
{
    public function getDescription(): string
    {
        return 'Add amount and expiration date to Adyen payment links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_adyen_payment_link ADD amount INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE sylius_adyen_payment_link ADD expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE sylius_adyen_payment_link SET amount = COALESCE((SELECT p.amount FROM sylius_payment p WHERE p.id = sylius_adyen_payment_link.payment_id), 0)');
        $this->addSql('ALTER TABLE sylius_adyen_payment_link ALTER COLUMN amount DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_adyen_payment_link DROP amount');
        $this->addSql('ALTER TABLE sylius_adyen_payment_link DROP expires_at');
    }
}
