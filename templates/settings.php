<div class="wrap">
    <h1> Configuration Settings </h1>
    <?php settings_errors(); ?>

    <form method="post" action="options.php">
        <?php
            settings_fields( 'ccart_settings_group' );
            do_settings_sections( 'coupons_plugin' );
            submit_button();
        ?>
    </form>

</div>