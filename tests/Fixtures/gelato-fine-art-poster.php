<?php
/**
 * Test data: the variants of Gelato's Fine Art Poster template (2026-11-15)
 * as GET /v1/templates/{id} names them: id, orientation, size, the print
 * area in mm (with 4 mm bleed), the placeholder and the catalog product
 * (ADR-115).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

return array(
	array( 'rp-0', 'Vertical', 'A2 (42 x 59.4  cm)', 428, 602, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_a2_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-1', 'Vertical', '45x45 cm / 18x18″', 458, 458, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_450x450-mm-18x18-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-2', 'Vertical', '75x100 cm / 30x40″', 758, 1008, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_750x1000-mm-30x40-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-3', 'Vertical', 'A1 (59.4 x 84.1  cm)', 602, 849, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_a1_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-4', 'Horizontal', '27x35 cm / 11x14″', 358, 278, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_270x350-mm-11x14-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-5', 'Horizontal', '30x40 cm / 12x16″', 408, 308, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_300x400-mm-12x16-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-6', 'Vertical', '25x25 cm / 10x10″', 258, 258, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_250x250-mm-10x10-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-7', 'Vertical', '50x70 cm / 20x28″', 508, 708, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_500x700-mm-20x28-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-8', 'Horizontal', 'A3 (29.7 x 42  cm)', 428, 305, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_a3_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-9', 'Vertical', '60x80 cm / 24x32″', 608, 808, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_600x800-mm-24x32-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-10', 'Horizontal', '25x25 cm / 10x10″', 258, 258, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_250x250-mm-10x10-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-11', 'Horizontal', '35x35 cm / 14x14″', 358, 358, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_350x350-mm-14x14-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-12', 'Horizontal', '40x60 cm / 16x24″', 608, 408, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_400x600-mm-16x24-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-13', 'Vertical', 'A3 (29.7 x 42  cm)', 305, 428, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_a3_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-14', 'Vertical', '40x60 cm / 16x24″', 408, 608, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_400x600-mm-16x24-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-15', 'Vertical', '30x30 cm / 12x12″', 308, 308, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_300x300-mm-12x12-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-16', 'Vertical', '30x45 cm / 12x18″', 308, 458, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_300x450-mm-12x18-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-17', 'Vertical', '35x35 cm / 14x14″', 358, 358, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_350x350-mm-14x14-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-18', 'Horizontal', '75x100 cm / 30x40″', 1008, 758, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_750x1000-mm-30x40-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-19', 'Vertical', '21x29.7 cm / 8x12"', 218, 305, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_a4-8x12-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-20', 'Vertical', '50x50 cm / 20x20″', 508, 508, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_500x500-mm-20x20-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-21', 'Vertical', '28x43  cm / XL (11x17″)', 288, 438, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_280x430-mm-xl_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-22', 'Vertical', '70x100 cm / 28x40″', 708, 1008, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_700x1000-mm-28x40-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-23', 'Horizontal', '60x80 cm / 24x32″', 808, 608, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_600x800-mm-24x32-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-24', 'Vertical', '45x60 cm / 18x24″', 458, 608, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_450x600-mm-18x24-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-25', 'Vertical', '20x50 cm / 8x20″', 208, 508, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_200x500-mm-8x20-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-26', 'Vertical', '13x18 cm / 5x7″', 138, 188, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_130x180-mm-5r_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-27', 'Horizontal', '20x25 cm / 8x10″', 258, 208, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_200x250-mm-8x10-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-28', 'Horizontal', '30x30 cm / 12x12″', 308, 308, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_300x300-mm-12x12-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-29', 'Horizontal', '50x70 cm / 20x28″', 708, 508, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_500x700-mm-20x28-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-30', 'Horizontal', '60x90 cm / 24x36″', 908, 608, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_600x900-mm-24x36-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-31', 'Vertical', '25x60 cm / 10x24″', 258, 608, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_250x600-mm-10x24-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-32', 'Horizontal', '25x60 cm / 10x24″', 608, 258, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_250x600-mm-10x24-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-33', 'Vertical', '27x35 cm / 11x14″', 278, 358, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_270x350-mm-11x14-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-34', 'Vertical', '70x70 cm / 28x28″', 708, 708, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_700x700-mm-28x28-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-35', 'Vertical', '30x40 cm / 12x16″', 308, 408, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_300x400-mm-12x16-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-36', 'Horizontal', '45x60 cm / 18x24″', 608, 458, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_450x600-mm-18x24-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-37', 'Horizontal', '40x50 cm / 16x20″', 508, 408, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_400x500-mm-16x20-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-38', 'Horizontal', '70x100 cm / 28x40″', 1008, 708, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_700x1000-mm-28x40-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-39', 'Horizontal', 'A0 (84.1 x 118.9  cm)', 1197, 849, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_a0_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-40', 'Vertical', '15x20 cm / 6x8″', 158, 208, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_150x200-mm-6x8-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-41', 'Vertical', '60x90 cm / 24x36″', 608, 908, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_600x900-mm-24x36-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-42', 'Horizontal', '20x50 cm / 8x20″', 508, 208, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_200x500-mm-8x20-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-43', 'Vertical', 'A0 (84.1 x 118.9  cm)', 849, 1197, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_a0_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-44', 'Horizontal', '21x29.7 cm / 8x12"', 305, 218, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_a4-8x12-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-45', 'Horizontal', '28x43  cm / XL (11x17″)', 438, 288, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_280x430-mm-xl_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-46', 'Horizontal', 'A2 (42 x 59.4  cm)', 602, 428, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_a2_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-47', 'Horizontal', '70x70 cm / 28x28″', 708, 708, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_700x700-mm-28x28-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-48', 'Vertical', '20x25 cm / 8x10″', 208, 258, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_200x250-mm-8x10-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-49', 'Horizontal', '15x20 cm / 6x8″', 208, 158, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_150x200-mm-6x8-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-50', 'Horizontal', '50x50 cm / 20x20″', 508, 508, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_500x500-mm-20x20-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-51', 'Vertical', '40x40 cm / 16x16″', 408, 408, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_400x400-mm-16x16-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-52', 'Vertical', '40x50 cm / 16x20″', 408, 508, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_ver_400x500-mm-16x20-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-53', 'Horizontal', '45x45 cm / 18x18″', 458, 458, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_450x450-mm-18x18-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-54', 'Horizontal', 'A1 (59.4 x 84.1  cm)', 849, 602, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_a1_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-55', 'Horizontal', '13x18 cm / 5x7″', 188, 138, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_130x180-mm-5r_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-56', 'Horizontal', '30x45 cm / 12x18″', 458, 308, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_300x450-mm-12x18-inch_200-gsm-80lb-enhanced-uncoated' ),
	array( 'rp-57', 'Horizontal', '40x40 cm / 16x16″', 408, 408, '1 - sort kasse.png', '1', 'fine_arts_poster_geo_simplified_product_12-0_hor_400x400-mm-16x16-inch_200-gsm-80lb-enhanced-uncoated' ),
);
