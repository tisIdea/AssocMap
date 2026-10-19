ALTER TABLE public.municipalities
ADD COLUMN locality_type VARCHAR(20) NOT NULL DEFAULT 'municipality';

ALTER TABLE public.municipalities
ADD CONSTRAINT municipalities_locality_type_check
CHECK (locality_type IN ('municipality', 'city'));