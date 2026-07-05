<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704051840 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE organizations CHANGE max_employees_override max_org_users_override INT DEFAULT NULL');
        $this->addSql('ALTER TABLE plans CHANGE max_employees max_org_users INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE organizations CHANGE max_org_users_override max_employees_override INT DEFAULT NULL');
        $this->addSql('ALTER TABLE plans CHANGE max_org_users max_employees INT NOT NULL');
    }
}
