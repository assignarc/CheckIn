<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104151000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notes JSON and unique_id VARCHAR(255) columns to org_users table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE org_users ADD unique_id VARCHAR(255) DEFAULT NULL, ADD notes JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE org_users DROP unique_id, DROP notes');
    }
}

