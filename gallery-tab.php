<section id="tab-gallery" class="tab">
  <div class="sub-layout" data-sub-layout>
    <aside class="sub-sidebar">
      <div class="sub-header">Gallery</div>
      <div class="sub-nav">
        <button type="button" class="sub-item active" data-pane="gallery-list">
          Photo Library
        </button>
        <button type="button" class="sub-item" data-pane="gallery-upload">
          Upload Photo
        </button>
        <button type="button" class="sub-item" data-pane="gallery-categories">
          Categories
        </button>
      </div>
    </aside>
    <div class="sub-content">
      <div class="sub-pane active" data-pane="gallery-list">
        <div class="card">
          <div class="table-header">
            <h3>Gallery photo list</h3>
            <button type="button" id="open-gallery-upload-modal" class="btn primary">Upload photo</button>
          </div>
          <div class="gallery-thumb-grid-wrapper">
            <div class="gallery-search-row">
              <label class="gallery-search-field">
                <span class="gallery-search-label">Search gallery photos</span>
                <input
                  type="search"
                  class="gallery-search-input"
                  data-gallery-search
                  placeholder="Search by photo title or category"
                  autocomplete="off"
                  aria-label="Search gallery photos by title or category"
                />
              </label>
              <span class="gallery-search-count" data-gallery-search-count>
                0 photos
              </span>
            </div>
            <div id="gallery-thumb-grid" class="gallery-thumb-grid"></div>
            <p class="muted gallery-thumb-loading hidden" data-gallery-loading>Loading photos…</p>
            <p id="gallery-thumb-empty" class="muted gallery-thumb-empty hidden">No photos uploaded yet.</p>
            <div class="gallery-thumb-actions">
              <button type="button" id="gallery-load-more" class="btn ghost hidden">Load More</button>
            </div>
          </div>
        </div>
      </div>
      <div class="sub-pane" data-pane="gallery-upload">
        <div class="card">
          <div class="section-header">
            <h3>Upload a photo</h3>
          </div>
          <form data-gallery-photo-form class="form" enctype="multipart/form-data">
            <div class="photo-uploader" data-photo-uploader="gallery">
              <div class="photo-preview">
                <img data-photo-image class="hidden" alt="" />
                <div data-photo-placeholder class="photo-placeholder">Drag & drop a photo or use the button below</div>
                <button type="button" class="photo-preview-clear hidden" data-photo-clear aria-label="Remove photo">Clear</button>
              </div>
              <div class="photo-actions">
                <input type="file" name="photo" data-photo-input accept="image/*" hidden />
                <button type="button" class="btn" data-photo-upload>Select photo</button>
              </div>
            </div>
            <div class="grid">
              <label class="field">
                <span>Photo title</span>
                <input name="title" type="text" required />
              </label>
              <label class="field">
                <span>Alternate text (alt)</span>
                <input name="alt_text" type="text" />
              </label>
              <label class="field">
                <span>Category</span>
              <select data-gallery-photo-category name="category_id">
                  <option value="">Select a category</option>
                </select>
              </label>
            </div>
            <div class="modal-actions">
              <button type="submit" class="btn primary">Upload photo</button>
            </div>
          </form>
        </div>
      </div>
      <div class="sub-pane" data-pane="gallery-categories">
        <div class="card">
          <div class="section-header">
            <h3>Manage categories</h3>
          </div>
          <form id="gallery-category-form" class="form grid full">
            <label class="field">
              <span>Category name</span>
              <input id="gallery-category-name" type="text" required />
            </label>
            <label class="field">
              <span>Mother category (optional)</span>
              <select id="gallery-category-mother" name="mother_category_id">
                <option value="">No mother category</option>
              </select>
            </label>
            <div class="modal-actions">
              <button type="button" class="btn hidden" id="gallery-category-cancel">Cancel</button>
              <button type="submit" class="btn primary" id="gallery-category-submit">Save category</button>
            </div>
          </form>
        </div>
        <div class="card gallery-category-list-card">
          <div class="section-header">
            <h3>Saved categories</h3>
          </div>
          <div class="gallery-category-table">
            <table>
              <thead>
              <tr>
                <th>Name</th>
                <th>Mother</th>
                <th>Actions</th>
              </tr>
              </thead>
              <tbody id="gallery-category-table-body"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
