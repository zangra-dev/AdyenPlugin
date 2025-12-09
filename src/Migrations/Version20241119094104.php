<?php

declare(strict_types=1);

namespace Sylius\AdyenPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241119094104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add column token to log table';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('sylius_adyen_log')->hasColumn('token'), 'Token already exists.');

        $this->addSql('ALTER TABLE sylius_adyen_log ADD token VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sylius_adyen_log DROP token');
    }
}
