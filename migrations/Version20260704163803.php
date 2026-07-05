<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704163803 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE categories ADD requires_location TINYINT DEFAULT 0 NOT NULL, ADD deleted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE managers ADD deleted_at DATETIME DEFAULT NULL, CHANGE is_active is_active TINYINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE org_users ADD deleted_at DATETIME DEFAULT NULL, CHANGE is_active is_active TINYINT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE organizations ADD deleted_at DATETIME DEFAULT NULL, CHANGE overtime_multiplier overtime_multiplier NUMERIC(4, 2) DEFAULT \'1.50\' NOT NULL');
        $this->addSql('ALTER TABLE work_sessions ADD deleted_at DATETIME DEFAULT NULL, CHANGE is_overtime is_overtime TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE categories DROP requires_location, DROP deleted_at');
        $this->addSql('ALTER TABLE managers DROP deleted_at, CHANGE is_active is_active TINYINT NOT NULL');
        $this->addSql('ALTER TABLE org_users DROP deleted_at, CHANGE is_active is_active TINYINT NOT NULL');
        $this->addSql('ALTER TABLE organizations DROP deleted_at, CHANGE overtime_multiplier overtime_multiplier NUMERIC(5, 2) NOT NULL');
        $this->addSql('ALTER TABLE work_sessions DROP deleted_at, CHANGE is_overtime is_overtime TINYINT NOT NULL');
    }
}
