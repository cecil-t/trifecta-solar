Tests for every outside service the proposal system uses. Admins only; nothing here is shown to anyone else, and the tests save nothing.

- **Google:** an address to a map point, the Solar API's roof faces for the building there, and an aerial image. **Check coverage** asks for roof models on rooftop projects, to see how often Google has one where Trifecta works.
- **Weather:** NSRDB typical-year weather for a point (downloaded once per grid cell and kept), with SAM's example system run on it.
- **Utility rates:** OpenEI rates in effect at a point, and SAM's bill for one as a check that SAM reads it right.
- **Rate history:** every OpenEI version of a place's rates billed at the same use, with the 3, 5 and 10 year yearly change.
- **SAM check** and a **test PDF**: the production engine and the PDF maker on the server run.
- **Reference jobs** compares the models with real, monitored systems.

The point Google finds fills the Weather and Utility rates forms.
