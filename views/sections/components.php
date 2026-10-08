<section class="component-library">
  <header class="component-library-heading">
    <div>
      <span class="component-kicker">FINTRACK UI KIT</span>
      <h1>Reusable components</h1>
      <p>Standalone interface patterns ready to reuse across the app.</p>
    </div>
    <span class="component-count">05 patterns</span>
  </header>

  <section class="component-grid" aria-label="Reusable UI component examples">
    <article class="component-preview">
      <header class="component-preview-label"><span>01</span><div><strong>Unavailable state</strong><small>Wishlist empty state</small></div></header>
      <div class="component-stage stage-wish"><?php require __DIR__ . '/../components/wish-unavailable.php'; ?></div>
    </article>
    <article class="component-preview">
      <header class="component-preview-label"><span>02</span><div><strong>Not found</strong><small>404 error state</small></div></header>
      <div class="component-stage stage-error"><?php require __DIR__ . '/../components/error-404.php'; ?></div>
    </article>
    <article class="component-preview">
      <header class="component-preview-label"><span>03</span><div><strong>Investment receipt</strong><small>Completed order summary</small></div></header>
      <div class="component-stage stage-receipt"><?php require __DIR__ . '/../components/investment-receipt.php'; ?></div>
    </article>
    <article class="component-preview">
      <header class="component-preview-label"><span>04</span><div><strong>Payment success</strong><small>Confirmation and details</small></div></header>
      <div class="component-stage stage-payment"><?php require __DIR__ . '/../components/payment-success.php'; ?></div>
    </article>
    <article class="component-preview">
      <header class="component-preview-label"><span>05</span><div><strong>Account menu</strong><small>Profile and preferences</small></div></header>
      <div class="component-stage stage-account"><?php require __DIR__ . '/../components/account-menu.php'; ?></div>
    </article>
  </section>
</section>
