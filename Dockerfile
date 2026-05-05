FROM wordpress:latest

# Install dependencies for unzip
RUN apt-get update && apt-get install -y unzip && rm -rf /var/lib/apt/lists/*

# Download and install WooCommerce plugin
RUN curl -o woocommerce.zip -L https://downloads.wordpress.org/plugin/woocommerce.zip \
    && unzip woocommerce.zip -d /usr/src/wordpress/wp-content/plugins/ \
    && rm woocommerce.zip

# Set proper permissions
RUN chown -R www-data:www-data /usr/src/wordpress/wp-content/plugins/woocommerce
