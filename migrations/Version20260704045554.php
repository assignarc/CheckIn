<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704045554 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE categories ADD color VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE org_users RENAME INDEX idx_ba82c30032c8a3de TO IDX_EACE568B32C8A3DE');
        $this->addSql('ALTER TABLE organizations ADD kiosk_token_rotation_hours INT NOT NULL, ADD logo_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE categories DROP color');
        $this->addSql('ALTER TABLE org_users RENAME INDEX idx_eace568b32c8a3de TO IDX_BA82C30032C8A3DE');
        $this->addSql('ALTER TABLE organizations DROP kiosk_token_rotation_hours, DROP logo_path');
    }
}
