<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UserManagementTest extends TestCase
{
    private UserContext $adminCtx;

    protected function setUp(): void
    {
        test_reset_all();
        $this->adminCtx = test_seed_admin();
    }

    // --- createUser ---

    public function testCreateUserWithPassword(): void
    {
        $id = UserManagement::createUser($this->adminCtx, [
            'first_name' => 'Dana',
            'last_name' => 'Rosenthal',
            'email' => 'Dana@Example.com',
            'password' => 'supersecret1',
        ]);

        $user = UserManagement::findById($id);
        $this->assertSame('dana@example.com', $user['email']); // normalized
        $this->assertTrue(password_verify('supersecret1', $user['password_hash']));
        $this->assertNotNull($user['email_verified_at']);
        $this->assertSame(0, (int)$user['is_admin']);
    }

    public function testCreateUserRequiresEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UserManagement::createUser($this->adminCtx, [
            'first_name' => 'Dana',
            'last_name' => 'Rosenthal',
            'password' => 'supersecret1',
        ]);
    }

    public function testCreateUserRejectsDuplicateEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UserManagement::createUser($this->adminCtx, [
            'first_name' => 'Dana',
            'last_name' => 'Rosenthal',
            'email' => 'admin@example.com', // seeded admin's email
            'password' => 'supersecret1',
        ]);
    }

    public function testCreateUserRequiresAdmin(): void
    {
        $nonAdmin = new UserContext(999, false);
        $this->expectException(RuntimeException::class);
        UserManagement::createUser($nonAdmin, [
            'first_name' => 'Eve',
            'last_name' => 'Rosenthal',
            'email' => 'eve@example.com',
            'password' => 'supersecret1',
        ]);
    }

    // --- Account activation (verify token + initial password setup) ---

    private function insertPendingUser(string $token): int
    {
        $st = pdo()->prepare(
            "INSERT INTO users (first_name, last_name, email, password_hash, is_admin, email_verify_token)
             VALUES ('Pending', 'User', 'pending@example.com', '', 0, ?)"
        );
        $st->execute([$token]);
        return (int)pdo()->lastInsertId();
    }

    public function testFindPendingPasswordSetupByToken(): void
    {
        $this->insertPendingUser('tok123');

        $user = UserManagement::findPendingPasswordSetupByToken('tok123');
        $this->assertNotNull($user);
        $this->assertSame('pending@example.com', $user['email']);

        $this->assertNull(UserManagement::findPendingPasswordSetupByToken('wrong'));
        $this->assertNull(UserManagement::findPendingPasswordSetupByToken(''));
    }

    public function testFindPendingPasswordSetupIgnoresUsersWithPassword(): void
    {
        pdo()->exec("INSERT INTO users (first_name, last_name, email, password_hash, email_verify_token)
                     VALUES ('Has', 'Password', 'has@example.com', 'somehash', 'tok456')");

        // Token exists but the account already has a password: not a setup candidate
        $this->assertNull(UserManagement::findPendingPasswordSetupByToken('tok456'));
        $this->assertNotNull(UserManagement::findByVerifyToken('tok456'));
    }

    public function testCompleteInitialPasswordSetup(): void
    {
        $id = $this->insertPendingUser('tok789');

        $user = UserManagement::completeInitialPasswordSetup('tok789', 'newpassword1');

        $this->assertNotNull($user);
        $this->assertSame($id, (int)$user['id']);
        $this->assertTrue(password_verify('newpassword1', $user['password_hash']));
        $this->assertNotNull($user['email_verified_at']);
        $this->assertNull($user['email_verify_token']);

        // Token is single-use
        $this->assertNull(UserManagement::completeInitialPasswordSetup('tok789', 'anotherpass1'));
    }

    public function testCompleteInitialPasswordSetupWithInvalidToken(): void
    {
        $this->assertNull(UserManagement::completeInitialPasswordSetup('nope', 'newpassword1'));
    }

    // --- Password reset ---

    public function testCompletePasswordReset(): void
    {
        $token = 'resettok123';
        pdo()->prepare('UPDATE users SET password_reset_token_hash=?, password_reset_expires_at=DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id=?')
            ->execute([hash('sha256', $token), $this->adminCtx->id]);

        $this->assertTrue(UserManagement::completePasswordReset($token, 'newpassword1'));

        $user = UserManagement::findById($this->adminCtx->id);
        $this->assertTrue(password_verify('newpassword1', $user['password_hash']));
        $this->assertNull($user['password_reset_token_hash']);
    }

    public function testExpiredResetTokenIsRejected(): void
    {
        $token = 'resettok456';
        pdo()->prepare('UPDATE users SET password_reset_token_hash=?, password_reset_expires_at=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id=?')
            ->execute([hash('sha256', $token), $this->adminCtx->id]);

        $this->assertNull(UserManagement::getUserByResetToken($token));
        $this->assertFalse(UserManagement::completePasswordReset($token, 'newpassword1'));
    }

    // --- updateProfile ---

    public function testUpdateProfileRejectsInvalidEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UserManagement::updateProfile($this->adminCtx, $this->adminCtx->id, ['email' => 'not-an-email']);
    }

    public function testUpdateProfileUpdatesNames(): void
    {
        $ok = UserManagement::updateProfile($this->adminCtx, $this->adminCtx->id, [
            'first_name' => 'Renamed',
            'last_name' => 'Person',
        ]);
        $this->assertTrue($ok);

        $user = UserManagement::findById($this->adminCtx->id);
        $this->assertSame('Renamed', $user['first_name']);
        $this->assertSame('Person', $user['last_name']);
    }

    public function testNonAdminCannotUpdateOtherUsers(): void
    {
        $nonAdmin = test_seed_user();
        $this->expectException(RuntimeException::class);
        UserManagement::updateProfile($nonAdmin, $this->adminCtx->id, ['first_name' => 'Hacked']);
    }

    // --- delete ---

    public function testDeleteUserRemovesTheirSiteButIsRefusedWhileTheyHaveContent(): void
    {
        $userCtx = test_seed_user();
        $siteId = SiteManagement::createForUser($this->adminCtx, $userCtx->id, 'Regular', 'Site');
        $categoryId = CategoryManagement::create($userCtx, $userCtx->id, ['name' => 'Keep me']);

        try {
            UserManagement::deleteUser($this->adminCtx, $userCtx->id);
            $this->fail('a user with categories must not be deletable (FK RESTRICT)');
        } catch (Throwable $e) {
            $this->assertNotNull(UserManagement::findById($userCtx->id));
        }

        CategoryManagement::delete($userCtx, $categoryId);
        $this->assertTrue(UserManagement::deleteUser($this->adminCtx, $userCtx->id));
        $this->assertNull(UserManagement::findById($userCtx->id));
        $this->assertNull(SiteManagement::findById($siteId), 'the site cascades with the user');
    }

    public function testCannotDeleteOwnAccount(): void
    {
        $this->expectException(RuntimeException::class);
        UserManagement::deleteUser($this->adminCtx, $this->adminCtx->id);
    }
}
