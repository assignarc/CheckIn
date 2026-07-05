<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704142437 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Allow inserting 0 into auto-increment temporarily for MySQL/MariaDB
        $this->addSql("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_AUTO_VALUE_ON_ZERO');");
        $this->addSql("INSERT IGNORE INTO organizations (id, name, slug, plan_id, created_at) VALUES (0, 'System', 'system-org', NULL, NOW());");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM organizations WHERE id = 0;");
    }
}
