<?php
/**
 * The creator's designer API.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Rest;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Http\RestController;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Alerts\Fonts\AlertFont;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProduct;
use MerchBoard\Modules\ProductDesigner\Bases\BaseProductRepository;
use MerchBoard\Modules\ProductDesigner\Bases\BaseView;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerDrafts;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerNames;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerProductRepository;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerShop;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerSubmissions;
use MerchBoard\Modules\ProductDesigner\Designs\DesignValidator;
use MerchBoard\Modules\ProductDesigner\Files\CreatorUploads;
use MerchBoard\Modules\ProductDesigner\Files\DesignerAssetRepository;
use MerchBoard\Modules\ProductDesigner\Files\DesignerFiles;
use MerchBoard\Modules\ProductDesigner\ThreadPacks;
use MerchBoard\Modules\WooCommerce\Sales\SalesAmount;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `/me/designer` (step 30.8, ADR-080 pkt. 1, 4–5), for the creator that
 * CreatorContext resolves (ADR-007), only while the designer is switched on
 * (404 `merchboard_designer_disabled` otherwise):
 *
 * - `GET /me/designer`: the active base products (price, colours, sizes,
 *   sides with their template layers), the cliparts, the creator's uploads,
 *   the approved fonts, the creator's designer products and the limits.
 * - `POST /me/designer/uploads` (multipart `file`), `DELETE …/uploads/{id}`
 *   (answers the state, as does deleting a product).
 * - `GET /me/designer/assets/{id}`: a file the creator may see, sent as the
 *   image itself (no-store, nosniff); the dashboard shows it as `data:`.
 * - `GET /me/designer/products` (step 30.14): every designer product of the
 *   creator with its state for the section Products: in the shop (price
 *   and link), waiting for approval (new or a change), rejected (the
 *   administrator's note), taken out of the shop, or a draft.
 * - `POST /me/designer/products` `{ base_id }`, `GET|PUT|DELETE
 *   /me/designer/products/{id}`: start, read, save the draft of and delete a
 *   designer product.
 * - `POST …/products/{id}/files` (multipart `file` and `slot`), `POST
 *   …/submit`, `POST …/withdraw` (step 30.9): the submission's files, the
 *   submission and its withdrawal (DesignerSubmissions); each answers the
 *   product. Files and submission are refused during "view as creator".
 * - `POST …/products/{id}/change` (step 30.10): a new draft of a live
 *   product. The product answers its live version and the note of a
 *   rejection that a draft continues.
 */
final class MeDesignerController extends RestController {

	private const ROUTE = '/me/designer';

	private const ASSET_ROUTE = '/merchboard/v1/me/designer/assets/(?P<id>[1-9][0-9]{0,18})';

	/**
	 * Constructor.
	 *
	 * @param CreatorContext            $context     The current creator.
	 * @param BaseProductRepository     $bases       Base products.
	 * @param DesignerProductRepository $products    Designer products.
	 * @param DesignerDrafts            $drafts      Drafts.
	 * @param DesignerSubmissions       $submissions Submissions.
	 * @param DesignerAssetRepository   $assets      File records.
	 * @param DesignerFiles             $files       Files.
	 * @param CreatorUploads            $uploads     The creator's uploads.
	 * @param AlertFonts                $fonts       Approved fonts.
	 * @param Logger                    $logger      Technical log.
	 * @param DesignerShop              $shop        The creator's decisions about the shop.
	 */
	public function __construct(
		private CreatorContext $context,
		private BaseProductRepository $bases,
		private DesignerProductRepository $products,
		private DesignerDrafts $drafts,
		private DesignerSubmissions $submissions,
		private DesignerAssetRepository $assets,
		private DesignerFiles $files,
		private CreatorUploads $uploads,
		private AlertFonts $fonts,
		Logger $logger,
		private DesignerShop $shop
	) {
		parent::__construct( $logger );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		$routes = array(
			''                                            => array(
				WP_REST_Server::READABLE => 'overview',
			),
			'/uploads'                                    => array(
				WP_REST_Server::CREATABLE => 'upload',
			),
			'/uploads/(?P<id>[1-9][0-9]{0,18})'           => array(
				WP_REST_Server::DELETABLE => 'delete_upload',
			),
			'/assets/(?P<id>[1-9][0-9]{0,18})'            => array(
				WP_REST_Server::READABLE => 'asset',
			),
			'/products'                                   => array(
				WP_REST_Server::READABLE  => 'products',
				WP_REST_Server::CREATABLE => 'create_product',
			),
			'/products/(?P<id>[1-9][0-9]{0,18})'          => array(
				WP_REST_Server::READABLE  => 'product',
				WP_REST_Server::EDITABLE  => 'save_product',
				WP_REST_Server::DELETABLE => 'delete_product',
			),
			'/products/(?P<id>[1-9][0-9]{0,18})/files'    => array(
				WP_REST_Server::CREATABLE => 'attach_file',
			),
			'/products/(?P<id>[1-9][0-9]{0,18})/submit'   => array(
				WP_REST_Server::CREATABLE => 'submit_product',
			),
			'/products/(?P<id>[1-9][0-9]{0,18})/withdraw' => array(
				WP_REST_Server::CREATABLE => 'withdraw_product',
			),
			'/products/(?P<id>[1-9][0-9]{0,18})/change'   => array(
				WP_REST_Server::CREATABLE => 'change_product',
			),
			'/products/(?P<id>[1-9][0-9]{0,18})/discard'  => array(
				WP_REST_Server::CREATABLE => 'discard_change',
			),
			'/products/(?P<id>[1-9][0-9]{0,18})/shop'     => array(
				WP_REST_Server::CREATABLE => 'set_shop',
			),
			'/deleted/(?P<id>[1-9][0-9]{0,18})'           => array(
				WP_REST_Server::DELETABLE => 'purge_design',
			),
			'/deleted/(?P<id>[1-9][0-9]{0,18})/preview'   => array(
				WP_REST_Server::READABLE => 'deleted_preview',
			),
		);

		foreach ( $routes as $path => $methods ) {
			$endpoints = array();

			foreach ( $methods as $method => $callback ) {
				$endpoints[] = array(
					'methods'             => $method,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(),
				);
			}

			register_rest_route( self::NAMESPACE, self::ROUTE . $path, $endpoints );
		}

		add_filter( 'rest_pre_serve_request', array( $this, 'serve_file' ), 10, 3 );
	}

	/**
	 * Permission callback: an active creator for the current user.
	 */
	public function permission(): bool|WP_Error {
		$creator = $this->require_creator( $this->context );

		return $creator instanceof WP_Error ? $creator : true;
	}

	/**
	 * `GET /me/designer`.
	 */
	public function overview(): WP_REST_Response|WP_Error {
		return $this->with_creator( self::ROUTE, fn( Creator $creator ): WP_REST_Response => new WP_REST_Response( $this->describe( $creator ), 200 ) );
	}

	/**
	 * `GET /me/designer/products` (step 30.14).
	 */
	public function products(): WP_REST_Response|WP_Error {
		return $this->with_creator( self::ROUTE . '/products', fn( Creator $creator ): WP_REST_Response => new WP_REST_Response( $this->states( $creator ), 200 ) );
	}

	/**
	 * `POST /me/designer/uploads`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/uploads',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				$files = $request->get_file_params();

				try {
					$asset = $this->uploads->add( $creator->id, is_array( $files['file'] ?? null ) ? $files['file'] : array() );
				} catch ( DesignerException $e ) {
					return self::refused( $e );
				}

				return new WP_REST_Response( self::asset_data( $asset ), 201 );
			}
		);
	}

	/**
	 * `DELETE /me/designer/uploads/{id}`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function delete_upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/uploads/{id}',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				try {
					$this->uploads->delete( $creator->id, (int) $request->get_param( 'id' ) );
				} catch ( DesignerException $e ) {
					return self::refused( $e );
				}

				return new WP_REST_Response( $this->describe( $creator ), 200 );
			}
		);
	}

	/**
	 * `GET /me/designer/assets/{id}`: the file, sent by serve_file().
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function asset( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/assets/{id}',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				$asset = $this->assets->find( (int) $request->get_param( 'id' ) );
				$path  = null === $asset || ! CreatorUploads::may_see( $creator->id, $asset ) || ! $this->layer_visible( $creator, $asset ) ? null : $this->files->path( $asset['file'] );
				$body  = null === $path ? false : file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of at most 25 MB.

				if ( null === $asset || false === $body ) {
					return self::error( 'merchboard_not_found', __( 'Not found.', 'merchboard' ), 404 );
				}

				$response = new WP_REST_Response( $body, 200 );
				$response->set_headers(
					array(
						'Content-Type'           => $asset['mime'],
						'Content-Disposition'    => 'inline',
						'Cache-Control'          => 'private, no-store',
						'X-Content-Type-Options' => 'nosniff',
					)
				);

				return $response;
			}
		);
	}

	/**
	 * Whether a template layer belongs to a base product the creator may see:
	 * an active one, or one the creator has a design on (review 30.15). Any
	 * other asset is not a layer and passes.
	 *
	 * @param Creator                                            $creator The creator.
	 * @param array{id: int, kind: string, creator_id: int|null} $asset   Asset record.
	 * @throws DatabaseException When the base products cannot be read.
	 */
	private function layer_visible( Creator $creator, array $asset ): bool {
		if ( DesignerAssetRepository::LAYER !== $asset['kind'] ) {
			return true;
		}

		$own = array_flip( array_map( static fn( array $row ): int => (int) $row['base_id'], $this->products->for_creator( $creator->id ) ) );

		foreach ( $this->bases->all() as $base ) {
			if ( ( BaseProduct::ACTIVE === $base->status || isset( $own[ (int) $base->id ] ) ) && in_array( $asset['id'], $base->layer_assets(), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * `POST /me/designer/products` `{ base_id }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create_product( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/products',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				$base = $request->get_param( 'base_id' );

				try {
					$id = $this->drafts->create( $creator, is_int( $base ) ? $base : 0 );
				} catch ( DesignerException $e ) {
					return self::refused( $e );
				}

				return $this->product_response( $creator, $id, 201 );
			}
		);
	}

	/**
	 * `GET /me/designer/products/{id}`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function product( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator( self::ROUTE . '/products/{id}', fn( Creator $creator ): WP_REST_Response|WP_Error => $this->product_response( $creator, (int) $request->get_param( 'id' ), 200 ) );
	}

	/**
	 * `PUT /me/designer/products/{id}`: saves the draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function save_product( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/products/{id}',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				$id = (int) $request->get_param( 'id' );
				try {
					// The JSON body's fields (the route's id is ignored by the service).
					$this->drafts->save( $creator, $id, $request->get_params(), $this->validator( $creator ) );
				} catch ( DesignerException $e ) {
					return self::refused( $e );
				}

				return $this->product_response( $creator, $id, 200 );
			}
		);
	}

	/**
	 * `DELETE /me/designer/products/{id}`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function delete_product( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/products/{id}',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				try {
					$this->shop->remove( $creator, (int) $request->get_param( 'id' ) );
				} catch ( DesignerException $e ) {
					return self::refused( $e );
				}

				return new WP_REST_Response( $this->describe( $creator ), 200 );
			}
		);
	}

	/**
	 * `DELETE /me/designer/deleted/{id}`: deletes a deleted design for good.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function purge_design( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/deleted/{id}',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				try {
					$this->shop->purge( $creator, (int) $request->get_param( 'id' ) );
				} catch ( DesignerException $e ) {
					return self::refused( $e );
				}

				return new WP_REST_Response( $this->describe( $creator ), 200 );
			}
		);
	}

	/**
	 * `GET /me/designer/deleted/{id}/preview`: the image of a deleted design.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function deleted_preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/deleted/{id}/preview',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				$id   = (int) $request->get_param( 'id' );
				$own  = in_array( $id, array_map( static fn( array $row ): int => (int) $row['id'], $this->products->deleted_for_creator( $creator->id ) ), true );
				$name = $own ? $this->products->preview_file( $id ) : null;
				$path = null === $name ? null : $this->files->path( $name );
				$body = null === $path ? false : file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A private file of the plugin's own directory.

				if ( null === $name || false === $body ) {
					return self::error( 'merchboard_not_found', __( 'Not found.', 'merchboard' ), 404 );
				}

				$response = new WP_REST_Response( $body, 200 );
				$response->set_headers(
					array(
						'Content-Type'           => self::image_type( $name ),
						'Content-Disposition'    => 'inline',
						'Cache-Control'          => 'private, no-store',
						'X-Content-Type-Options' => 'nosniff',
					)
				);

				return $response;
			}
		);
	}

	/**
	 * The type of a stored image.
	 *
	 * @param string|null $name File name.
	 */
	private static function image_type( ?string $name ): ?string {
		return match ( null === $name ? null : pathinfo( $name, PATHINFO_EXTENSION ) ) {
			null   => null,
			'png'  => 'image/png',
			'webp' => 'image/webp',
			default => 'image/jpeg',
		};
	}

	/**
	 * `POST /me/designer/products/{id}/discard`: drops the change of a product in the shop.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function discard_change( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/products/{id}/discard',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				$id = (int) $request->get_param( 'id' );

				try {
					$this->shop->discard( $creator, $id );
				} catch ( DesignerException $e ) {
					return self::refused( $e );
				}

				return $this->product_response( $creator, $id, 200 );
			}
		);
	}

	/**
	 * `POST /me/designer/products/{id}/shop`: takes a product out of the shop or puts it back.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function set_shop( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/products/{id}/shop',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				$id = (int) $request->get_param( 'id' );

				try {
					$this->shop->set_published( $creator, $id, true === $request->get_param( 'published' ) );
				} catch ( DesignerException $e ) {
					return self::refused( $e );
				}

				return $this->product_response( $creator, $id, 200 );
			}
		);
	}

	/**
	 * `POST /me/designer/products/{id}/files`: one file of the submission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function attach_file( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/products/{id}/files',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				$id    = (int) $request->get_param( 'id' );
				$files = $request->get_file_params();
				$slot  = $request->get_param( 'slot' );

				try {
					$this->submissions->attach( $creator, $id, is_string( $slot ) ? $slot : '', is_array( $files['file'] ?? null ) ? $files['file'] : array() );
				} catch ( DesignerException $e ) {
					return self::refused( $e );
				}

				return $this->product_response( $creator, $id, 200 );
			}
		);
	}

	/**
	 * `POST /me/designer/products/{id}/submit`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function submit_product( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/products/{id}/submit',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				$id = (int) $request->get_param( 'id' );

				try {
					$this->submissions->submit( $creator, $id );
				} catch ( DesignerException $e ) {
					return self::refused( $e );
				}

				return $this->product_response( $creator, $id, 200 );
			}
		);
	}

	/**
	 * `POST /me/designer/products/{id}/withdraw`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function withdraw_product( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/products/{id}/withdraw',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				$id = (int) $request->get_param( 'id' );

				try {
					$this->submissions->withdraw( $creator, $id );
				} catch ( DesignerException $e ) {
					return self::refused( $e );
				}

				return $this->product_response( $creator, $id, 200 );
			}
		);
	}

	/**
	 * `POST /me/designer/products/{id}/change`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function change_product( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->with_creator(
			self::ROUTE . '/products/{id}/change',
			function ( Creator $creator ) use ( $request ): WP_REST_Response|WP_Error {
				$id = (int) $request->get_param( 'id' );

				try {
					$this->submissions->change( $creator, $id );
				} catch ( DesignerException $e ) {
					return self::refused( $e );
				}

				return $this->product_response( $creator, $id, 200 );
			}
		);
	}

	/**
	 * `rest_pre_serve_request`: sends a successful asset response as the image
	 * itself instead of JSON; every other response is left to the server.
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @param mixed           $served  Whether the request was already served.
	 * @param mixed           $result  The response.
	 * @param WP_REST_Request $request Request.
	 */
	public function serve_file( mixed $served, mixed $result, WP_REST_Request $request ): bool {
		$served = (bool) $served;

		if ( $served || ! $result instanceof WP_REST_Response || 'GET' !== $request->get_method() || 200 !== $result->get_status()
			|| self::ASSET_ROUTE !== $result->get_matched_route() || ! in_array( $result->get_headers()['Content-Type'] ?? null, DesignerFiles::TYPES, true ) ) {
			return $served;
		}

		$body = $result->get_data();

		if ( ! is_string( $body ) ) {
			return $served;
		}

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- An image the server validated, sent with its type and nosniff.

		return true;
	}

	/**
	 * The design check for a creator: own uploads and cliparts, approved fonts.
	 *
	 * @param Creator $creator The creator.
	 */
	private function validator( Creator $creator ): DesignValidator {
		return new DesignValidator(
			function ( int $asset_id ) use ( $creator ): bool {
				$asset = $this->assets->find( $asset_id );

				return null !== $asset && CreatorUploads::may_use( $creator->id, $asset );
			},
			array_values( array_map( static fn( AlertFont $font ): string => $font->family, $this->fonts->approved() ) )
		);
	}

	/**
	 * The designer's state for the creator.
	 *
	 * @param Creator $creator The creator.
	 * @return array<string, mixed>
	 * @throws DatabaseException When it cannot be read.
	 */
	private function describe( Creator $creator ): array {
		$bases = array_values( array_filter( $this->bases->all(), static fn( BaseProduct $base ): bool => BaseProduct::ACTIVE === $base->status ) );

		return array(
			'creator'  => $creator->display_name,
			'bases'    => array_map( self::base_data( ... ), $bases ),
			'cliparts' => array_map( self::asset_data( ... ), $this->assets->shared( DesignerAssetRepository::CLIPART ) ),
			'uploads'  => array_map( self::asset_data( ... ), $this->assets->owned( $creator->id, CreatorUploads::UPLOAD ) ),
			'fonts'    => array_values( array_map( static fn( AlertFont $font ): array => $font->to_array(), $this->fonts->approved() ) ),
			'products' => array_map(
				fn( array $row ): array => array(
					'id'         => (int) $row['id'],
					'base_id'    => (int) $row['base_id'],
					'title'      => (string) $row['title'],
					'status'     => (string) $row['version_status'],
					'state'      => $this->state( $row ),
					'change'     => null !== $this->products->live( (int) $row['id'] ) && DesignerProductRepository::DRAFT === $row['version_status'],
					'in_shop'    => null !== $row['wc_product_id'],
					'updated_at' => mysql_to_rfc3339( (string) $row['updated_at'] ),
				),
				$this->products->for_creator( $creator->id )
			),
			'deleted'  => array_map(
				fn( array $row ): array => array(
					'id'         => (int) $row['id'],
					'title'      => (string) $row['title'],
					'base'       => (string) ( $this->bases->find( (int) $row['base_id'] )->name ?? '' ),
					'preview'    => self::image_type( $this->products->preview_file( (int) $row['id'] ) ),
					'in_shop'    => null !== $row['wc_product_id'],
					'deleted_at' => mysql_to_rfc3339( (string) $row['updated_at'] ),
				),
				$this->products->deleted_for_creator( $creator->id )
			),
			'limits'   => array(
				'max_upload_bytes' => DesignerFiles::max_bytes(),
				'max_uploads'      => CreatorUploads::MAX_FILES,
				'max_objects'      => DesignValidator::MAX_OBJECTS,
				'price_decimals'   => function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2,
			),
		);
	}

	/**
	 * A product's state: `retired` (taken out of the shop with its base
	 * product), `in_shop` (a live version in the shop), `hidden` (a live
	 * version the creator took out of the shop), `waiting` (a new product
	 * waiting for approval), `rejected` (the last decision was a rejection) or
	 * `draft`. A change next to a live version does not change it.
	 *
	 * @param array<string, mixed> $row The product: `id`, `status`, `wc_product_id`.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function state( array $row ): string {
		$id    = (int) $row['id'];
		$wc_id = is_numeric( $row['wc_product_id'] ?? null ) ? (int) $row['wc_product_id'] : 0;

		return match ( true ) {
			DesignerProductRepository::RETIRED === $row['status'] => 'retired',
			null !== $this->products->live( $id ) => $wc_id > 0 && 'publish' === get_post_status( $wc_id ) ? 'in_shop' : 'hidden',
			null !== $this->products->pending( $id ) => 'waiting',
			null !== $this->products->last_rejection( $id ) => 'rejected',
			default => 'draft',
		};
	}

	/**
	 * The creator's designer products with their state, newest first. A
	 * product in the shop can have a change waiting or rejected next to it.
	 *
	 * @param Creator $creator The creator.
	 * @return list<array<string, mixed>>
	 * @throws DatabaseException When it cannot be read.
	 */
	private function states( Creator $creator ): array {
		$currencies = array();
		$states     = array();
		$price      = static fn( mixed $value ): ?string => null === $value ? null : SalesAmount::format( SalesAmount::parse( (string) $value ) );

		foreach ( $this->bases->all() as $base ) {
			$currencies[ (int) $base->id ] = $base->currency;
		}

		foreach ( $this->products->for_creator( $creator->id ) as $row ) {
			$id       = (int) $row['id'];
			$product  = $this->products->find( $creator->id, $id );
			$live     = $this->products->live( $id );
			$pending  = $this->products->pending( $id );
			$refusal  = $this->products->last_rejection( $id );
			$wc_id    = is_numeric( $product['wc_product_id'] ?? null ) ? (int) $product['wc_product_id'] : 0;
			$retired  = DesignerProductRepository::RETIRED === ( $product['status'] ?? null );
			$state    = $this->state( $row );
			$url      = 'in_shop' === $state && $wc_id > 0 && 'publish' === get_post_status( $wc_id ) ? get_permalink( $wc_id ) : false;
			$states[] = array(
				'id'        => $id,
				'title'     => (string) ( $live['title'] ?? $row['title'] ),
				'state'     => $state,
				'currency'  => $currencies[ (int) $row['base_id'] ] ?? '',
				'price'     => null === $live ? null : $price( $product['shop_price'] ?? $live['quoted_price'] ),
				'url'       => is_string( $url ) ? $url : null,
				'pending'   => null === $pending || $retired ? null : array(
					'number'       => (int) $pending['number'],
					'price'        => $price( $pending['quoted_price'] ),
					'submitted_at' => mysql_to_rfc3339( (string) $pending['submitted_at'] ),
				),
				'rejection' => null === $refusal || null !== $pending || $retired ? null : array(
					'number'     => (int) $refusal['number'],
					'note'       => (string) $refusal['note'],
					'decided_at' => mysql_to_rfc3339( (string) $refusal['decided_at'] ),
				),
				'draft'     => null !== $this->products->draft( $id ),
			);
		}

		return $states;
	}

	/**
	 * A designer product with its draft.
	 *
	 * @param Creator $creator The creator.
	 * @param int     $id      Designer product.
	 * @param int     $status  HTTP status.
	 * @throws DatabaseException When it cannot be read.
	 */
	private function product_response( Creator $creator, int $id, int $status ): WP_REST_Response|WP_Error {
		$product = $this->products->find( $creator->id, $id );
		$draft   = null === $product ? null : $this->products->draft( $id );
		$pending = null === $product ? null : $this->products->pending( $id );
		$live    = null === $product ? null : $this->products->live( $id );
		$refusal = null === $product ? null : $this->products->last_rejection( $id );
		$price   = static fn( mixed $value ): ?string => null === $value ? null : SalesAmount::format( SalesAmount::parse( (string) $value ) );

		if ( null === $product ) {
			return self::error( 'merchboard_not_found', __( 'Not found.', 'merchboard' ), 404 );
		}

		$base_name = (string) ( $this->bases->find( (int) $product['base_id'] )->name ?? '' );

		$json = static function ( mixed $value ): mixed {
			$decoded = is_string( $value ) ? json_decode( $value, true ) : null;

			return is_array( $decoded ) ? $decoded : array();
		};

		return new WP_REST_Response(
			array(
				'id'        => (int) $product['id'],
				'base_id'   => (int) $product['base_id'],
				'in_shop'   => null !== $product['wc_product_id'],
				'state'     => $this->state( $product ),
				'draft'     => null === $draft ? null : array(
					'number'       => (int) $draft['number'],
					'title'        => (string) $draft['title'],
					'postfix'      => DesignerNames::postfix( (string) $draft['title'], DesignerNames::prefix( $creator->display_name, $base_name ), $base_name ),
					'description'  => (string) $draft['description'],
					'colors'       => $json( $draft['colors'] ),
					'sizes'        => $json( $draft['sizes'] ),
					'design'       => $json( $draft['design'] ),
					'profit_kind'  => (string) $draft['profit_kind'],
					'profit_value' => (string) $draft['profit_value'],
					'price'        => null === $draft['quoted_price'] ? null : SalesAmount::format( SalesAmount::parse( (string) $draft['quoted_price'] ) ),
					'base_version' => (int) $draft['base_version'],
					'updated_at'   => mysql_to_rfc3339( (string) $draft['updated_at'] ),
				),
				'pending'   => null === $pending ? null : array(
					'number'       => (int) $pending['number'],
					'title'        => (string) $pending['title'],
					'price'        => $price( $pending['quoted_price'] ),
					'submitted_at' => mysql_to_rfc3339( (string) $pending['submitted_at'] ),
				),
				'live'      => null === $live ? null : array(
					'number' => (int) $live['number'],
					'title'  => (string) $live['title'],
					'price'  => $price( $live['quoted_price'] ),
				),
				'rejection' => null === $refusal ? null : array(
					'number'     => (int) $refusal['number'],
					'note'       => (string) $refusal['note'],
					'decided_at' => mysql_to_rfc3339( (string) $refusal['decided_at'] ),
				),
			),
			$status
		);
	}

	/**
	 * A base product for the designer.
	 *
	 * @param BaseProduct $base Base product.
	 * @return array<string, mixed>
	 */
	private static function base_data( BaseProduct $base ): array {
		return array(
			'id'               => (int) $base->id,
			'name'             => $base->name,
			'description'      => $base->description,
			'base_price'       => SalesAmount::format( $base->base_units ),
			'extra_side_price' => SalesAmount::format( $base->extra_side_units ),
			'currency'         => $base->currency,
			'colors'           => $base->colors,
			'sizes'            => $base->sizes,
			'version'          => $base->version,
			'views'            => array_map(
				static function ( BaseView $view ): array {
					[ $print_width, $print_height ] = $view->print_pixels();

					$pack = '' === $view->thread_pack ? null : ThreadPacks::find( $view->thread_pack );

					return array(
						'key'          => $view->key,
						'name'         => $view->name,
						'width'        => $view->width,
						'height'       => $view->height,
						'layers'       => (object) $view->layers,
						'area'         => $view->area,
						'print_width'  => $print_width,
						'print_height' => $print_height,
						'dpi'          => $view->dpi,
						'flat'         => $view->flat,
						'embroidery'   => null === $pack ? null : array(
							'max'     => $pack['max'],
							'threads' => $pack['threads'],
						),
					);
				},
				$base->views
			),
		);
	}

	/**
	 * An asset for the designer.
	 *
	 * @param array{id: int, name: string, width: int, height: int, mime: string} $asset Asset record.
	 * @return array{id: int, name: string, width: int, height: int, mime: string}
	 */
	private static function asset_data( array $asset ): array {
		return array(
			'id'     => $asset['id'],
			'name'   => $asset['name'],
			'width'  => $asset['width'],
			'height' => $asset['height'],
			'mime'   => $asset['mime'],
		);
	}

	/**
	 * Runs an endpoint for the current creator while the designer is on.
	 *
	 * @param string                                         $route    Route for the log.
	 * @param callable(Creator): (WP_REST_Response|WP_Error) $callback Endpoint body.
	 */
	private function with_creator( string $route, callable $callback ): WP_REST_Response|WP_Error {
		return $this->run(
			$route,
			function () use ( $callback ): WP_REST_Response|WP_Error {
				if ( ! Settings::designer_enabled() ) {
					return self::error( 'merchboard_designer_disabled', __( 'The product designer is not available.', 'merchboard' ), 404 );
				}

				$creator = $this->require_creator( $this->context );

				return $creator instanceof WP_Error ? $creator : $callback( $creator );
			}
		);
	}

	/**
	 * A refusal as an error with the reason as code.
	 *
	 * @param DesignerException $e Refusal.
	 */
	private static function refused( DesignerException $e ): WP_Error {
		$reason = $e->reason();
		$status = in_array( $reason, array( DesignerException::DESIGN_UNKNOWN, DesignerException::UPLOAD_UNKNOWN, DesignerException::BASE_UNKNOWN ), true ) ? 404 : 400;

		return self::error( 'merchboard_designer_refused', __( 'The product designer refused the change.', 'merchboard' ), $status, array( 'reason' => $reason ) );
	}
}
