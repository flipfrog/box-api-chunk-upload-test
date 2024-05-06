all: data/test.dat

data/test.dat: generate_test_file
	./generate_test_file 10
