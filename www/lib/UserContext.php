<?php
declare(strict_types=1);

class UserContext {
  public int $id;
  public bool $admin;
  public bool $super; // logged in via the super password (testing backdoor)

  private static ?UserContext $ctx = null;

  public function __construct(int $id, bool $admin, bool $super = false) {
    $this->id = $id;
    $this->admin = $admin;
    $this->super = $super;
  }

  // Store the context for this request
  public static function set(UserContext $ctx): void {
    self::$ctx = $ctx;
  }

  // Fetch the context for this request (or null if not set)
  public static function getLoggedInUserContext(): ?UserContext {
    return self::$ctx;
  }

  /** @deprecated Use getLoggedInUserContext() */
  public static function getUserContext(): ?UserContext {
    return self::getLoggedInUserContext();
  }

  // Initialize context from session without hitting the DB
  public static function bootstrapFromSession(): void {
    if (self::$ctx !== null) return;
    if (!empty($_SESSION['uid'])) {
      $uid = (int)$_SESSION['uid'];
      $isAdmin = !empty($_SESSION['is_admin']);
      $isSuper = !empty($_SESSION['is_super']);
      self::$ctx = new UserContext($uid, $isAdmin, $isSuper);
    }
  }
}
