#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <errno.h>

#define BUF_SIZE (1024 * 1024)

int main(int ac, char** av)
{
  char *buf = malloc(BUF_SIZE);
  int mbytes = 0;

  if (ac < 2) {
    fprintf(stderr, "USAGE: %s <M bytes>\n", av[0]);
    exit(1);
  }

  memset(buf, 0x41, BUF_SIZE); // 'A'
  sscanf(av[1], "%i", &mbytes);
 
  FILE* fp = fopen("./data/test.dat", "w");
  if (fp == NULL) {
    fprintf(stderr, "file open error.\n");
    exit(errno);
  }

  for (int i = 0; i < mbytes; i++) {
    fwrite(buf, BUF_SIZE, 1, fp);
  }

  fclose(fp);
}
